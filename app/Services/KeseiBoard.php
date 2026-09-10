<?php

namespace App\Services;

use App\Models\CalendarEntry;
use App\Models\KeseiPart;
use App\Models\KeseiScan;
use App\Models\PatternGroupItem;
use App\Models\StockSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds everything the Kesei board renders — a fixed 07:00 → 07:00 clock face
 * that loops forever, red decrease ticks that never drop before a row's
 * run-day closing, and the Closing Time table that fills as parts close.
 *
 * Shared by the standalone /andon-kesei page and the KESEI card embedded in
 * the pattern-driven Andon board, so both show the exact same component.
 *
 * A Kesei row's Timeline Stok is the SUM of its "stock_source" part_no(s)'
 * Stock Part All readings (falling back to the row's own part_no).
 */
class KeseiBoard
{
    private const PX_PER_MINUTE = 1.8;

    /** Minute-of-day the clock face starts at (07:00 = start of shift 1). */
    private const DAY_ANCHOR_MINUTE = 7 * 60;

    private const WINDOW_MINUTES = 24 * 60;

    /**
     * The full view-data array for the board partials.
     *
     * @param  'stock'|'scan'  $tickSource  Where the red ticks come from —
     *                                      'stock' = decreases in the Stock Part All API feed (the original
     *                                      board); 'scan' = scanned SOS labels (kesei_scans).
     * @return array<string, mixed>
     */
    public function data(string $tickSource = 'stock'): array
    {
        $now = now();
        // Only the *time* (07:00) matters here — the blade uses it for the hour
        // axis labels, which wrap 07 → 06 → 07.
        $anchor = $now->copy()->setTime(7, 0);
        $historyFloor = $now->copy()->subDays(KeseiPart::FOLD_HISTORY_DAYS);

        $keseiRows = KeseiPart::with(['part', 'patternBoards'])
            ->orderBy('urutan')
            ->orderBy('id')
            ->get()
            ->map(function (KeseiPart $kesei) use ($now, $historyFloor) {
                $closing = $kesei->closing_time;
                [$foldStart, $cycleStart] = $kesei->foldBoundaries($now);

                return [
                    'id' => $kesei->id,
                    'label' => $kesei->part?->part_no ?? '(part terhapus)',
                    'level' => $kesei->level,
                    'qty_kbn' => $kesei->part?->qty_kbn,
                    'sources' => $kesei->sourcePartNos(),
                    'patterns' => $kesei->patternBoards->pluck('name')->all(),
                    // The pattern of the run this closing is for.
                    'planned_pattern' => $kesei->plannedPatternName($now),
                    // Is this part actively running under today's Calendar pattern?
                    'runs_today' => $kesei->isRunningNow($now),
                    // Has it passed its closing for the current run? Only then
                    // does it appear in the Closing Time table.
                    'closed_now' => $kesei->closedForCurrentRun($now),
                    'closing_label' => $closing?->format('H:i'),
                    'closing_minute' => $closing ? $this->clockMinute($closing) : null,
                    'closing_reached' => $foldStart !== null,
                    // Visible pile starts after the last closing; when none has
                    // passed, show everything within the history window.
                    'fold_start' => $foldStart ?? $historyFloor,
                    'cycle_start' => $cycleStart,
                ];
            })
            ->filter(fn (array $row) => $row['sources'] !== [])
            ->values();

        $queryStart = $this->queryStart($keseiRows, $now, $historyFloor);

        // The Timeline Stok table is always the API stock feed, on both boards.
        [$seedStock, $stockByTime] = $this->loadStock($keseiRows, $queryStart, $now);

        $allEvents = $tickSource === 'scan'
            ? $this->buildScanEvents($keseiRows, $queryStart, $now)
            : $this->buildStockDecreaseEvents($keseiRows, $seedStock, $stockByTime);
        [$stockDecreaseEvents, $closingKanban] = $this->splitEvents($keseiRows, $allEvents);

        return [
            'keseiRows' => $keseiRows,
            // The Closing Time table starts empty and only lists a part once it
            // has passed its closing for the current run — newest closing on top.
            'closingRows' => $keseiRows->where('closed_now', true)
                ->sortByDesc(fn (array $row) => $row['fold_start']->getTimestamp())
                ->values(),
            'stockDecreaseEvents' => $stockDecreaseEvents,
            'closingKanban' => $closingKanban,
            // The Timeline Stok table is a rolling 48h (captures land every
            // 15 min, so ~192 rows) — the pile-forever rule is only for the
            // red ticks.
            'stockHistoryRows' => $this->buildStockHistoryRows($keseiRows, $stockByTime, $now->copy()->subDays(2)),
            // Positions are minutes past 07:00 on the looping clock face.
            'dayStart' => 0,
            'timelineEnd' => self::WINDOW_MINUTES,
            'pxPerMinute' => self::PX_PER_MINUTE,
            'windowStart' => $anchor,
            'productionLabel' => CalendarEntry::productionDayStart($now)->locale('id')->translatedFormat('l, d F Y').' · 07:00 → 07:00',
            // The pattern the Calendar says is running now (rolls at 07:00).
            'currentPattern' => CalendarEntry::runningPatternBoard($now)?->name,
            // Where "now" sits on the clock face, for the moving now-line.
            'nowMinute' => $this->clockMinute($now),
        ];
    }

    /**
     * The visible red-tick decrease events for ONE Kesei part, computed without
     * building the whole board. The scanner's hot path uses this so a single
     * scan doesn't trigger a full-board rebuild (all parts + 48h of snapshots +
     * a calendar query per run-day per part).
     *
     * @return array{row: array<string, mixed>, events: array<int, array{kanban: int, at: Carbon}>}|null
     */
    public function rowContext(KeseiPart $part): ?array
    {
        $now = now();
        $historyFloor = $now->copy()->subDays(KeseiPart::FOLD_HISTORY_DAYS);

        $sources = $part->sourcePartNos();

        if ($sources === []) {
            return null;
        }

        [$foldStart, $cycleStart] = $part->foldBoundaries($now);

        $row = [
            'id' => $part->id,
            'label' => $part->part?->part_no ?? '(part terhapus)',
            'qty_kbn' => $part->part?->qty_kbn,
            'sources' => $sources,
            'closing_reached' => $foldStart !== null,
            'fold_start' => $foldStart ?? $historyFloor,
            'cycle_start' => $cycleStart,
        ];

        $rows = collect([$row]);
        $queryStart = $this->queryStart($rows, $now, $historyFloor);
        [$seedStock, $stockByTime] = $this->loadStock($rows, $queryStart, $now);
        [$visible] = $this->splitEvents($rows, $this->buildStockDecreaseEvents($rows, $seedStock, $stockByTime));

        return ['row' => $row, 'events' => $visible[$part->id] ?? []];
    }

    /**
     * A wall-clock time mapped onto the looping 07:00 → 07:00 face, as minutes
     * past 07:00 (0..1439).
     */
    private function clockMinute(Carbon $time): int
    {
        $minuteOfDay = $time->hour * 60 + $time->minute;

        return (int) (($minuteOfDay - self::DAY_ANCHOR_MINUTE + self::WINDOW_MINUTES) % self::WINDOW_MINUTES);
    }

    /**
     * The earliest instant any row needs stock history from (floored to the
     * hour, clamped to the history floor), so one query covers every row.
     * Also never later than 48h ago, so the Timeline Stok table always has a
     * full 2-day window regardless of how recently each part closed.
     */
    private function queryStart(Collection $keseiRows, Carbon $now, Carbon $historyFloor): Carbon
    {
        $earliest = $keseiRows
            ->map(fn (array $row) => $row['cycle_start']->getTimestamp())
            ->min();

        $start = $earliest !== null
            ? $now->copy()->setTimestamp($earliest)->startOfHour()
            : $now->copy()->subDay()->startOfHour();

        $timelineFloor = $now->copy()->subDays(2)->startOfHour();
        if ($start->gt($timelineFloor)) {
            $start = $timelineFloor;
        }

        return $start->lt($historyFloor) ? $historyFloor->copy()->startOfHour() : $start;
    }

    /**
     * Split every decrease event into what the timeline shows (after the row's
     * foldStart) and what folded into the accumulated number at the last
     * run-day closing (only when that closing has actually passed).
     *
     * @param  array<int, array<int, array{minute: int, kanban: int, pcs: int, time: string, at: Carbon}>>  $allEvents
     * @return array{0: array<int, array<int, array{minute: int, kanban: int, pcs: int, time: string}>>, 1: array<int, int>}
     */
    private function splitEvents(Collection $keseiRows, array $allEvents): array
    {
        $visible = [];
        $closingKanban = [];

        foreach ($keseiRows as $row) {
            $rowEvents = $allEvents[$row['id']] ?? [];

            $visible[$row['id']] = array_values(array_filter(
                $rowEvents,
                fn (array $event) => $event['at']->gt($row['fold_start'])
            ));

            if ($row['closing_reached']) {
                $lo = $row['cycle_start'];
                $hi = $row['fold_start'];

                $closingKanban[$row['id']] = array_sum(array_map(
                    fn (array $event) => $event['kanban'],
                    array_filter(
                        $rowEvents,
                        fn (array $event) => $event['at']->gt($lo) && $event['at']->lte($hi)
                    )
                ));
            }
        }

        return [$visible, $closingKanban];
    }

    /**
     * One snapshot query for every source part_no across all rows. Returns:
     *  - seed:      [part_no => stock] at the last capture before $windowStart
     *  - byTime:    [ 'Y-m-d H:i:s' => ['stock' => [part_no => int], 'std_min' => [part_no => int]] ]
     *               for captures inside the window, in chronological order.
     *
     * @return array{0: array<string, int>, 1: array<string, array{stock: array<string, int>, std_min: array<string, int>}>}
     */
    private function loadStock(Collection $keseiRows, Carbon $windowStart, Carbon $windowEnd): array
    {
        $sourceNos = $keseiRows->flatMap(fn (array $row) => $row['sources'])->unique()->values()->all();

        if ($sourceNos === []) {
            return [[], []];
        }

        $columns = ['part_no', 'stock', 'std_min', 'captured_at'];

        $seedRows = StockSnapshot::whereIn('part_no', $sourceNos)
            ->whereBetween('captured_at', [$windowStart->copy()->subDay(), $windowStart->copy()->subSecond()])
            ->orderBy('part_no')
            ->orderByDesc('captured_at')
            ->get($columns)
            ->unique('part_no');

        $seedStock = [];
        foreach ($seedRows as $row) {
            $seedStock[$row->part_no] = (int) $row->stock;
        }

        $byTime = [];
        $windowRows = StockSnapshot::whereIn('part_no', $sourceNos)
            ->whereBetween('captured_at', [$windowStart, $windowEnd])
            ->orderBy('captured_at')
            ->get($columns);

        foreach ($windowRows as $row) {
            $key = $row->captured_at->toDateTimeString();
            $byTime[$key]['stock'][$row->part_no] = (int) $row->stock;
            $byTime[$key]['std_min'][$row->part_no] = (int) $row->std_min;
        }

        return [$seedStock, $byTime];
    }

    /**
     * Sum a row's source part_no stock (or std_min) from one snapshot map.
     * Returns [sum, howManySourcesHadAReading].
     *
     * @param  array<string, int>  $map
     * @param  array<int, string>  $sources
     * @return array{0: int, 1: int}
     */
    private function sumSources(array $map, array $sources): array
    {
        $sum = 0;
        $present = 0;

        foreach ($sources as $partNo) {
            if (array_key_exists($partNo, $map)) {
                $sum += $map[$partNo];
                $present++;
            }
        }

        return [$sum, $present];
    }

    /**
     * Timeline Stok: one row per capture timestamp from $since onward, each
     * carrying every Kesei row's summed stock keyed by kesei_part id. A row
     * whose sources had no reading at that timestamp is simply absent.
     *
     * @param  array<string, array{stock: array<string, int>, std_min: array<string, int>}>  $stockByTime
     * @return array<int, array{time: string, values: array<int, array{stock: int, under_min: bool}>}>
     */
    private function buildStockHistoryRows(Collection $keseiRows, array $stockByTime, Carbon $since): array
    {
        if ($keseiRows->isEmpty()) {
            return [];
        }

        $rows = [];

        foreach ($stockByTime as $timestamp => $maps) {
            if (Carbon::parse($timestamp)->lt($since)) {
                continue;
            }

            $values = [];

            foreach ($keseiRows as $row) {
                [$stock, $present] = $this->sumSources($maps['stock'] ?? [], $row['sources']);

                if ($present === 0) {
                    continue;
                }

                [$stdMin] = $this->sumSources($maps['std_min'] ?? [], $row['sources']);

                $values[$row['id']] = ['stock' => $stock, 'under_min' => $stock < $stdMin];
            }

            $rows[] = ['time' => Carbon::parse($timestamp)->format('H:i'), 'values' => $values];
        }

        return $rows;
    }

    /**
     * For each Kesei row, every moment its summed source stock dropped between
     * two 15-minute captures, converted to kanban (lot ÷ qty_kbn rounded up).
     * Only decreases produce a tick. Positions are on the looping clock face,
     * so ticks from different days can share an x position — that's fine.
     *
     * @param  array<string, int>  $seedStock
     * @param  array<string, array{stock: array<string, int>, std_min: array<string, int>}>  $stockByTime
     * @return array<int, array<int, array{minute: int, kanban: int, pcs: int, time: string, at: Carbon}>>
     */
    private function buildStockDecreaseEvents(Collection $keseiRows, array $seedStock, array $stockByTime): array
    {
        if ($keseiRows->isEmpty()) {
            return [];
        }

        $events = [];

        foreach ($keseiRows as $row) {
            [$seedSum, $seedPresent] = $this->sumSources($seedStock, $row['sources']);
            $previous = $seedPresent > 0 ? $seedSum : null;
            $rowEvents = [];

            foreach ($stockByTime as $timestamp => $maps) {
                [$stock, $present] = $this->sumSources($maps['stock'] ?? [], $row['sources']);

                if ($present === 0) {
                    continue;
                }

                $decreasePcs = $previous !== null ? $previous - $stock : 0;

                if ($decreasePcs > 0) {
                    $kanban = PatternGroupItem::calculateTotalKanban($decreasePcs, $row['qty_kbn']);

                    if ($kanban > 0) {
                        $at = Carbon::parse($timestamp);
                        $rowEvents[] = [
                            'minute' => $this->clockMinute($at),
                            'kanban' => $kanban,
                            'pcs' => $decreasePcs,
                            'time' => $at->format('H:i'),
                            'at' => $at,
                        ];
                    }
                }

                $previous = $stock;
            }

            $events[$row['id']] = $rowEvents;
        }

        return $events;
    }

    /**
     * Scan-driven ticks: one event per scanned SOS label (1 kanban each),
     * positioned on the clock face by when it was scanned. A scan is matched
     * to the Kesei row whose own part_no or a source part_no equals it.
     *
     * @return array<int, array<int, array{minute: int, kanban: int, pcs: int, time: string, at: Carbon}>>
     */
    private function buildScanEvents(Collection $keseiRows, Carbon $from, Carbon $to): array
    {
        if ($keseiRows->isEmpty()) {
            return [];
        }

        // part_no => kesei_part id (own label and every source point to the row).
        $rowByPartNo = [];
        foreach ($keseiRows as $row) {
            foreach (array_merge([$row['label']], $row['sources']) as $partNo) {
                $rowByPartNo[$partNo] = $row['id'];
            }
        }

        $events = array_fill_keys($keseiRows->pluck('id')->all(), []);

        KeseiScan::whereIn('part_no', array_keys($rowByPartNo))
            ->whereBetween('scanned_at', [$from, $to])
            ->orderBy('scanned_at')
            ->get(['part_no', 'scanned_at'])
            ->each(function (KeseiScan $scan) use (&$events, $rowByPartNo) {
                $rowId = $rowByPartNo[$scan->part_no] ?? null;
                if ($rowId === null) {
                    return;
                }

                $events[$rowId][] = [
                    'minute' => $this->clockMinute($scan->scanned_at),
                    'kanban' => 1,
                    'pcs' => 1,
                    'time' => $scan->scanned_at->format('H:i'),
                    'at' => $scan->scanned_at,
                ];
            });

        return $events;
    }
}
