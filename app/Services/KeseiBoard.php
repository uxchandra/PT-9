<?php

namespace App\Services;

use App\Models\CalendarEntry;
use App\Models\KeseiPart;
use App\Models\KeseiPartClosing;
use App\Models\KeseiScan;
use App\Models\LotMaking;
use App\Models\LotMakingAssignment;
use App\Models\LotMakingPlanning;
use App\Models\LotMakingScan;
use App\Models\PatternGroupItem;
use App\Models\StockSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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
        // The scan board polls every few seconds so a scan shows up almost
        // instantly. Keying the cache by the newest row id of whichever table
        // actually drives the ticks means a genuinely new scan/stock reading
        // is never stale — it changes the key — while repeated polls in
        // between (the common case) share one computed board instead of
        // rebuilding it from scratch every few seconds.
        $freshness = $tickSource === 'scan'
            ? KeseiScan::max('id') ?? 0
            : StockSnapshot::max('id') ?? 0;

        $frozen = Cache::remember(
            "kesei-board:{$tickSource}:{$freshness}",
            5,
            fn () => $this->freeze($this->build($tickSource))
        );

        return $this->thaw($frozen);
    }

    /**
     * Carbon instances don't reliably survive the database/file cache's
     * serialize()/unserialize() round-trip — under load the class can come
     * back as `_PHP_Incomplete_Class`, which then breaks both Blade
     * ($windowStart->copy()->...) and any query that binds the value
     * (KeseiPull's ->where('scanned_at', '>', $row['fold_start'])). So
     * nothing but plain strings/arrays ever goes into the cache — every
     * Carbon here is flattened to a "Y-m-d H:i:s" string, and thaw() rebuilds
     * real Carbon instances the moment the data comes back out, cache hit or
     * not, so every consumer sees exactly the shape build() always returned.
     *
     * @return array<string, mixed>
     */
    private function freeze(array $data): array
    {
        $freezeRow = fn (array $row) => [
            ...$row,
            'fold_start' => $row['fold_start']->toDateTimeString(),
            'cycle_start' => $row['cycle_start']->toDateTimeString(),
        ];

        $data['keseiRows'] = collect($data['keseiRows'])->map($freezeRow)->all();
        $data['closingRows'] = collect($data['closingRows'])->map($freezeRow)->all();
        $data['stockDecreaseEvents'] = collect($data['stockDecreaseEvents'])
            ->map(fn (array $events) => collect($events)
                ->map(fn (array $e) => [...$e, 'at' => $e['at']->toDateTimeString()])
                ->all())
            ->all();
        $data['windowStart'] = $data['windowStart']->toDateTimeString();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function thaw(array $data): array
    {
        $thawRow = fn (array $row) => [
            ...$row,
            'fold_start' => Carbon::parse($row['fold_start']),
            'cycle_start' => Carbon::parse($row['cycle_start']),
        ];

        $data['keseiRows'] = collect($data['keseiRows'])->map($thawRow);
        $data['closingRows'] = collect($data['closingRows'])->map($thawRow);
        $data['stockDecreaseEvents'] = collect($data['stockDecreaseEvents'])
            ->map(fn (array $events) => collect($events)
                ->map(fn (array $e) => [...$e, 'at' => Carbon::parse($e['at'])])
                ->all())
            ->all();
        $data['windowStart'] = Carbon::parse($data['windowStart']);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function build(string $tickSource): array
    {
        $now = now();
        // Only the *time* (07:00) matters here — the blade uses it for the hour
        // axis labels, which wrap 07 → 06 → 07.
        $anchor = $now->copy()->setTime(7, 0);
        $historyFloor = $now->copy()->subDays(KeseiPart::FOLD_HISTORY_DAYS);

        $keseiRows = KeseiPart::with(['part', 'patternBoards', 'closings'])
            ->orderBy('urutan')
            ->orderBy('id')
            ->get()
            ->map(function (KeseiPart $kesei) use ($now, $historyFloor, $tickSource) {
                [$foldStart, $cycleStart] = $kesei->foldBoundaries($now);
                // Canonical "pola" — every pattern this row belongs to,
                // alphabetised into one string (e.g. "AC", "ABCD") so it
                // matches polaColor()'s lookup table regardless of the
                // order patternBoards() happens to return them in.
                $pola = $kesei->patternBoards->pluck('name')->unique()->sort()->implode('');

                return [
                    'id' => $kesei->id,
                    'label' => $kesei->part?->part_no ?? '(part terhapus)',
                    'level' => $kesei->level,
                    // "Perintah Pulling" — see KeseiPull::demandRow() for how
                    // this seeds the Finish Goods target on top of stock
                    // decreases seen since it was set. Stored as a plain
                    // string here (not a Carbon) so it survives the board
                    // cache's freeze/thaw round-trip without special-casing.
                    'pulling_command' => $kesei->pulling_command,
                    'pulling_command_set_at' => $kesei->pulling_command_set_at?->toDateTimeString(),
                    // Lead Time per Kanban (minutes) — see
                    // KeseiBoard::heijunkaRelease() / KeseiPull::demandRow().
                    'lt_per_kbn' => $kesei->lt_per_kbn,
                    // Material (RM) this part is built from — its own stock
                    // status ('material_status') is filled in below, once
                    // every row's material_part_no is known.
                    'material_part_no' => $kesei->material_part_no,
                    'qty_kbn' => $kesei->part?->qty_kbn,
                    'sources' => $kesei->sourcePartNos(),
                    'patterns' => $kesei->patternBoards->pluck('name')->all(),
                    'pola' => $pola,
                    'pola_color' => $this->polaColor($pola),
                    // The pattern of the run this closing is for.
                    'planned_pattern' => $kesei->plannedPatternName($now),
                    // Is this part actively running under today's Calendar
                    // pattern? The Heijunka board isn't pattern-driven at
                    // all — every part is treated as active there,
                    // regardless of what's running today.
                    'runs_today' => $tickSource === 'heijunka' ? true : $kesei->isRunningNow($now),
                    // Has it passed its closing for the current run? Only then
                    // does it appear in the Closing Time table.
                    'closed_now' => $kesei->closedForCurrentRun($now),
                    // Which specific closing time just fired — a part can carry
                    // several (e.g. 05:00 and 15:00); this is whichever is freshest.
                    'closing_label' => $foldStart?->format('H:i'),
                    // Every configured closing time's position on the looping
                    // clock face, for drawing a marker per definition.
                    'closing_markers' => $kesei->closings
                        ->map(fn (KeseiPartClosing $c) => [
                            'minute' => $this->clockMinute($c->closing_time),
                            'label' => $c->closing_time->format('H:i'),
                        ])
                        ->unique('minute')
                        ->values()
                        ->all(),
                    'closing_reached' => $foldStart !== null,
                    // Visible pile starts after the last closing; when none has
                    // passed, show everything within the history window.
                    'fold_start' => $foldStart ?? $historyFloor,
                    'cycle_start' => $cycleStart,
                ];
            })
            ->filter(fn (array $row) => $row['sources'] !== [])
            // Heijunka only paces Finish Goods demand (that's the only card
            // its releases feed into — see KeseiPull) — other levels (e.g.
            // Store 3) have no heijunka story, so they're left off this
            // board entirely rather than showing up with nothing useful to
            // say. Same level values KeseiPull::LOCATIONS['finish-goods']
            // matches against.
            ->when($tickSource === 'heijunka', fn (Collection $rows) => $rows->filter(
                fn (array $row) => in_array(strtoupper(trim((string) $row['level'])), ['FINISH GOODS', 'FINISH GOOD', 'FG'], true)
            ))
            // Lot Making's own Finish Goods parts share this same Heijunka
            // board/timeline — one combined pile instead of two separate
            // boards. See heijunkaLotMakingRows().
            ->when($tickSource === 'heijunka', fn (Collection $rows) => $rows->concat($this->heijunkaLotMakingRows($historyFloor)))
            // Parts actively running under today's pattern float to the top —
            // stable sort, so within "running" and "not running" each keeps
            // its normal urutan/id order.
            ->sortByDesc('runs_today')
            ->values();

        $materialStock = $this->latestStockByPartNo(
            $keseiRows->pluck('material_part_no')->filter()->unique()->values()->all()
        );
        $keseiRows = $keseiRows->map(function (array $row) use ($materialStock) {
            $row['material_status'] = $row['material_part_no'] === null
                ? null
                : (($materialStock[$row['material_part_no']] ?? 0) > 0 ? 'ready' : 'empty');

            return $row;
        });

        $queryStart = $this->queryStart($keseiRows, $now, $historyFloor);

        // The Timeline Stok table is always the API stock feed, on both boards.
        [$seedStock, $stockByTime] = $this->loadStock($keseiRows, $queryStart, $now);

        $allEvents = $tickSource === 'scan'
            ? $this->buildScanEvents($keseiRows, $queryStart, $now)
            : $this->buildStockDecreaseEvents($keseiRows, $seedStock, $stockByTime);

        // Heijunka: the raw stock-decrease events aren't shown as-is — each
        // row's own pile is re-timed through its Lead Time per Kanban first
        // (see heijunkaRelease()), same underlying stock feed as the normal
        // board, just paced instead of appearing all at once. Every release
        // stays on the board once crossed by the progress bar (unlike
        // Perintah Pulling demand, which drops it the instant it's crossed —
        // see KeseiPull) — see heijunkaVisualEvents() for the green/red/blue
        // colour states this tags each one with, and when a stale scanned
        // one finally gets dropped.
        if ($tickSource === 'heijunka') {
            $ltByRowId = $keseiRows->pluck('lt_per_kbn', 'id');
            $scannedCountByRowId = $this->heijunkaScannedCounts($keseiRows);

            $allEvents = collect($allEvents)
                ->map(fn (array $events, $rowId) => $this->heijunkaVisualEvents(
                    collect($events),
                    (int) ($ltByRowId[$rowId] ?? 0),
                    $scannedCountByRowId[$rowId] ?? 0
                )->all())
                ->all();
        }

        [$stockDecreaseEvents, $closingKanban] = $this->splitEvents($keseiRows, $allEvents);

        return [
            'keseiRows' => $keseiRows,
            // The Closing Time table starts empty and only lists a part once it
            // has passed its closing for the current run — newest closing on top.
            'closingRows' => $keseiRows->where('closed_now', true)
                ->sortByDesc(fn (array $row) => $row['fold_start']->getTimestamp())
                ->values()
                ->all(),
            'stockDecreaseEvents' => $stockDecreaseEvents,
            'closingKanban' => $closingKanban,
            // The Timeline Stok table is a rolling 48h (captures land every
            // 15 min, so ~192 rows) — the pile-forever rule is only for the
            // red ticks.
            'stockHistoryRows' => $this->buildStockHistoryRows($keseiRows, $stockByTime, $now->copy()->subDays(2)),
            // Antrian (Fix Volume): every still-Open Lot Making Planning
            // proses step — a queue driven by completed kanban volume
            // (jumlah_proses), not a clock time, which is what sets it apart
            // from the Closing Time (Fix Time) table above it.
            'openLotMakingQueue' => $this->loadOpenLotMakingQueue(),
            // Every pola -> color pairing, for the header's Closing Time legend.
            'polaLegend' => $this->polaLegend(),
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
            'pulling_command' => $part->pulling_command,
            'pulling_command_set_at' => $part->pulling_command_set_at?->toDateTimeString(),
            'lt_per_kbn' => $part->lt_per_kbn,
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
     * The fixed pola -> color legend — the only pattern-board combinations
     * actually run on this line. Order is the display order of the legend.
     */
    private const POLA_COLORS = [
        'ABCD' => '#3b82f6', // blue
        'AC' => '#22c55e', // green
        'BD' => '#f97316', // orange
        'A' => '#a855f7', // purple
        'C' => '#eab308', // yellow
        'D' => '#ffffff', // white
        'B' => '#ec4899', // pink
    ];

    /**
     * The color a row's "pola" (its full pattern-board combination, e.g.
     * "AC" or "ABCD") reads as on the board — used for the Closing Time
     * marker drawn on that row's timeline. A combination outside the fixed
     * set above (never actually run on this line) falls back to a neutral
     * grey.
     */
    private function polaColor(string $pola): string
    {
        return self::POLA_COLORS[$pola] ?? '#94a3b8';
    }

    /**
     * The same pola -> color legend, exposed for the header's Closing Time
     * legend to render one swatch per pola instead of hardcoding the colors
     * a second time.
     *
     * @return array<string, string>
     */
    public function polaLegend(): array
    {
        return self::POLA_COLORS;
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
     * The most recent captured stock for each part_no, regardless of how old
     * — used for the Material (RM) Ready/Empty status on the Closing Time
     * panel, which cares about "is there any known stock at all" rather than
     * a specific time window.
     *
     * @param  array<int, string>  $partNos
     * @return array<string, int>
     */
    private function latestStockByPartNo(array $partNos): array
    {
        if ($partNos === []) {
            return [];
        }

        return StockSnapshot::whereIn('part_no', $partNos)
            ->orderBy('part_no')
            ->orderByDesc('captured_at')
            ->get(['part_no', 'stock'])
            ->unique('part_no')
            ->pluck('stock', 'part_no')
            ->map(fn ($stock) => (int) $stock)
            ->all();
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
     * Every still-Open Lot Making Planning proses step, oldest first — the
     * exact same per-step status computation as
     * LotMakingPlanningController::index()'s Open branch: a lot with
     * jumlah_proses steps contributes one row here per step that has no
     * assignment yet. created_at is pre-formatted (not left as Carbon) since
     * this whole array round-trips through KeseiBoard's cache.
     *
     * @return array<int, array{created_at: string, part_no: string, lot: int, proses: ?int, jumlah_proses: ?int, machine: ?string}>
     */
    private function loadOpenLotMakingQueue(): array
    {
        $plannings = LotMakingPlanning::with(['part.lotMaking', 'assignments'])
            ->orderBy('created_at')
            ->get();

        // "Assignment Machine" master data (see LotMakingAssignment) — which
        // machine(s) are set up to run each part+proses, independent of
        // whether today's specific lot has actually been scheduled to one
        // yet. Shown purely for reference on an otherwise-still-Open row.
        $partIds = $plannings->pluck('part_id')->unique()->filter()->values();
        $assignmentsByPart = LotMakingAssignment::whereIn('part_id', $partIds)
            ->with('machine')
            ->get()
            ->groupBy('part_id');

        $rows = [];

        foreach ($plannings as $planning) {
            $jumlahProses = $planning->part?->lotMaking?->jumlah_proses;
            $partNo = $planning->part?->part_no ?? '(part terhapus)';
            $partAssignments = $assignmentsByPart->get($planning->part_id, collect());

            if ($jumlahProses === null) {
                // No steps definable yet — the whole lot counts as one Open
                // row, but only as long as nothing has been assigned to it
                // at all (there's no per-step tracking to fall back on here,
                // so "has any assignment" is the only signal available that
                // it's no longer simply waiting).
                if ($planning->assignments->isEmpty()) {
                    $rows[] = [
                        'created_at' => $planning->created_at->format('d/m H:i'),
                        'part_no' => $partNo,
                        'lot' => $planning->lot,
                        'proses' => null,
                        'jumlah_proses' => null,
                        // No specific step known yet — list every machine
                        // this part is set up to run on at all.
                        'machine' => $partAssignments->pluck('machine.name')->filter()->unique()->sort()->implode(', ') ?: null,
                    ];
                }

                continue;
            }

            // While still Open, proses doesn't matter yet — it's only decided
            // once staff actually submits an assignment for a specific step
            // (see LotMakingPlanningController::assign). So as long as ANY
            // step of this lot is still unassigned, it's one Open row for
            // the part as a whole, not one row per still-open step — those
            // would otherwise look like plain duplicates here (same part,
            // same lot, same time), which is exactly what they'd look like
            // since nothing distinguishes them until they're assigned.
            $openStepCount = $jumlahProses - $planning->assignments->count();

            if ($openStepCount > 0) {
                $rows[] = [
                    'created_at' => $planning->created_at->format('d/m H:i'),
                    'part_no' => $partNo,
                    'lot' => $planning->lot,
                    'proses' => null,
                    'jumlah_proses' => $jumlahProses,
                    // No specific step known yet — list every machine this
                    // part is set up to run on at all.
                    'machine' => $partAssignments->pluck('machine.name')->filter()->unique()->sort()->implode(', ') ?: null,
                ];
            }
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
     * Heijunka-paced version of a part's raw decrease events, used by
     * KeseiPull for every part's "Perintah Pulling" demand (see
     * KeseiPull::demandRow()): instead of every kanban in a batch becoming
     * due all at once, they "release" one at a time, lt_per_kbn minutes
     * apart, queued FIFO — see heijunkaRelease() for the queueing itself.
     * Only releases at/before right now are returned — the rest haven't
     * been "crossed" by the progress bar/now-line yet, so they don't count
     * toward demand yet. lt_per_kbn <= 0 is a no-op — every kanban releases
     * at its own arrival time, mathematically identical to the raw events
     * (just exploded to one entry per unit) — so a part with no Lead Time
     * per Kanban set behaves exactly as it always has.
     *
     * @param  Collection<int, array{minute: int, kanban: int, pcs: int, time: string, at: Carbon}>  $events
     * @return Collection<int, array{minute: int, kanban: int, pcs: int, time: string, at: Carbon}>
     */
    public function heijunkaEvents(Collection $events, int $ltPerKbn): Collection
    {
        $now = now();

        return $this->heijunkaRelease($events, $ltPerKbn)
            ->filter(fn (array $e) => $e['at']->lte($now))
            ->values();
    }

    /**
     * Every Lot Making part whose level is Finish Goods, shaped exactly like
     * a KeseiPart row (see build()) so the rest of the heijunka pipeline —
     * buildStockDecreaseEvents(), heijunkaScannedCounts(), splitEvents(),
     * the blade — can't tell the two apart. Ids are string-prefixed
     * ("lm-{id}") since LotMaking and KeseiPart both auto-increment from 1
     * and would otherwise collide as array/event keys.
     *
     * Lot Making has no closing-time concept at all (see LotMakingPull's
     * class doc) — every row's "pile" is simply everything within the same
     * rolling history floor Kesei falls back to when a part has no closing
     * configured, and it never folds on its own.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function heijunkaLotMakingRows(Carbon $historyFloor): Collection
    {
        return LotMaking::with('part')
            ->where('level', LotMaking::LEVEL_FINISH_GOODS)
            ->get()
            ->filter(fn (LotMaking $lm) => $lm->part?->part_no !== null)
            ->map(fn (LotMaking $lm) => [
                'id' => 'lm-'.$lm->id,
                'label' => $lm->part->part_no,
                'level' => $lm->level,
                'pulling_command' => $lm->pulling_command,
                'pulling_command_set_at' => $lm->pulling_command_set_at?->toDateTimeString(),
                'lt_per_kbn' => $lm->lt_per_kbn,
                'material_part_no' => $lm->material_part_no,
                'qty_kbn' => $lm->part->qty_kbn,
                'sources' => [$lm->part->part_no],
                'patterns' => [],
                'pola' => '',
                'pola_color' => $this->polaColor(''),
                'planned_pattern' => '-',
                'runs_today' => true,
                'closed_now' => false,
                'closing_label' => null,
                'closing_markers' => [],
                'closing_reached' => false,
                'fold_start' => $historyFloor,
                'cycle_start' => $historyFloor,
            ])
            ->values();
    }

    /**
     * The Andon Kesei Heijunka board's own tick set — every release, paced
     * or not, tagged with a 'heijunka_status' the blade uses to colour it
     * (see andon-kesei/_timeline-dark.blade.php):
     *
     *  - 'pending' (green) — not yet crossed by the progress bar, OR just
     *    crossed within the last 15 minutes and not yet scanned (a grace
     *    period before it's flagged as overdue).
     *  - 'overdue' (red) — crossed more than 15 minutes ago and still not
     *    scanned.
     *  - 'scanned' (blue) — crossed, and matched to an actual scan (see
     *    below).
     *
     * Unlike heijunkaEvents() (used for Perintah Pulling demand), a crossed
     * tick is NOT dropped the instant it's crossed — it stays on the board so
     * staff can see what's overdue. A 'scanned' (blue) tick never disappears
     * on its own either — it's kept forever precisely so the release order
     * stays visually auditable (was each kanban actually pulled in the
     * right sequence?). Only a still-unscanned tick gets capped, at 24h
     * (WINDOW_MINUTES) — without that, a part with no closing time
     * configured would pile up tick lines from many different calendar days
     * onto the one wrapping 24h clock face, aliasing on top of each other
     * into what looks like a random mess. Either way, Perintah Pulling
     * demand (KeseiPull) is untouched — old unfulfilled kanban still count
     * there regardless of age.
     *
     * "Scanned" isn't tracked per-tick anywhere (KeseiScan only records that
     * a scan happened, not which specific kanban it fulfilled), so it's
     * approximated by FIFO: of the $scannedCount most recent scans logged
     * for this row, the EARLIEST-crossed ticks are assumed fulfilled first —
     * the same oldest-demand-first assumption a real kanban pull follows.
     *
     * @param  Collection<int, array{minute: int, kanban: int, pcs: int, time: string, at: Carbon}>  $events
     * @return Collection<int, array{minute: int, kanban: int, pcs: int, time: string, at: Carbon, heijunka_status: string}>
     */
    public function heijunkaVisualEvents(Collection $events, int $ltPerKbn, int $scannedCount): Collection
    {
        $now = now();
        $released = $this->heijunkaRelease($events, $ltPerKbn)->sortBy('at')->values();

        $crossedTotal = $released->filter(fn (array $e) => $e['at']->lte($now))->count();
        $scannedOfCrossed = min($scannedCount, $crossedTotal);

        $crossedSeen = 0;
        $result = collect();

        foreach ($released as $event) {
            if ($event['at']->gt($now)) {
                $event['heijunka_status'] = 'pending';
                $result->push($event);

                continue;
            }

            $crossedSeen++;
            $minutesSinceCrossed = $event['at']->diffInMinutes($now);

            if ($crossedSeen <= $scannedOfCrossed) {
                $event['heijunka_status'] = 'scanned';
            } else {
                // Only an unscanned tick is capped — see the class doc above
                // for why a scanned one is exempt.
                if ($minutesSinceCrossed > self::WINDOW_MINUTES) {
                    continue;
                }

                $event['heijunka_status'] = $minutesSinceCrossed > 15 ? 'overdue' : 'pending';
            }

            $result->push($event);
        }

        return $result->values();
    }

    /**
     * How many scans have landed for each row since its own fold_start —
     * used by heijunkaVisualEvents() to work out which crossed ticks have
     * actually been fulfilled. One query covers every row.
     *
     * @param  Collection<int, array<string, mixed>>  $keseiRows
     * @return array<int, int>
     */
    private function heijunkaScannedCounts(Collection $keseiRows): array
    {
        if ($keseiRows->isEmpty()) {
            return [];
        }

        $rowByPartNo = [];
        foreach ($keseiRows as $row) {
            foreach (array_merge([$row['label']], $row['sources']) as $partNo) {
                $rowByPartNo[$partNo] = $row['id'];
            }
        }

        $foldStartByRow = $keseiRows->pluck('fold_start', 'id');
        $earliestFoldStart = $foldStartByRow->min();

        $counts = array_fill_keys($keseiRows->pluck('id')->all(), 0);

        if ($rowByPartNo === [] || $earliestFoldStart === null) {
            return $counts;
        }

        $tally = function (Collection $scans) use (&$counts, $rowByPartNo, $foldStartByRow) {
            foreach ($scans as $scan) {
                $rowId = $rowByPartNo[$scan->part_no] ?? null;

                if ($rowId === null) {
                    continue;
                }

                $foldStart = $foldStartByRow[$rowId] ?? null;

                if ($foldStart !== null && $scan->scanned_at->lte($foldStart)) {
                    continue;
                }

                $counts[$rowId] = ($counts[$rowId] ?? 0) + 1;
            }
        };

        $tally(KeseiScan::whereIn('part_no', array_keys($rowByPartNo))
            ->where('scanned_at', '>', $earliestFoldStart)
            ->get(['part_no', 'scanned_at']));

        // Merged Lot Making (Finish Goods) rows share this same board — their
        // scans live in a separate table (see ScannerController::scan()),
        // but a given part_no only ever belongs to one system or the other,
        // so merging both counts here can't double-count anything.
        $tally(LotMakingScan::whereIn('part_no', array_keys($rowByPartNo))
            ->where('scanned_at', '>', $earliestFoldStart)
            ->get(['part_no', 'scanned_at']));

        return $counts;
    }

    /**
     * A single-server FIFO queue: each event's kanban units wait for their
     * turn, lt_per_kbn minutes apart, starting no earlier than the event's
     * own arrival — but if the queue is still busy releasing an earlier
     * batch when this one arrives, its units queue up right behind them
     * (starting at whichever is later: this event's arrival, or the moment
     * the queue frees up) instead of bursting in on top of them.
     *
     * @param  Collection<int, array{kanban: int, at: Carbon}>  $events
     * @return Collection<int, array{minute: int, kanban: int, pcs: int, time: string, at: Carbon}>
     */
    private function heijunkaRelease(Collection $events, int $ltPerKbn): Collection
    {
        $released = collect();
        $queueFreeAt = null; // Carbon|null — when the queue is next free to start releasing a new unit.

        foreach ($events->sortBy('at')->values() as $event) {
            $start = $ltPerKbn > 0 && $queueFreeAt !== null && $queueFreeAt->gt($event['at'])
                ? $queueFreeAt
                : $event['at'];

            for ($i = 1; $i <= $event['kanban']; $i++) {
                $at = $ltPerKbn > 0 ? $start->copy()->addMinutes($ltPerKbn * $i) : $start->copy();
                $released->push([
                    'minute' => $this->clockMinute($at),
                    'kanban' => 1,
                    'pcs' => 1,
                    'time' => $at->format('H:i'),
                    'at' => $at,
                ]);
            }

            $queueFreeAt = $ltPerKbn > 0 ? $start->copy()->addMinutes($ltPerKbn * $event['kanban']) : $start;
        }

        return $released->values();
    }

    /**
     * Scan-driven ticks: one event per minute a row got scanned in, kanban
     * = how many scans landed in that minute. Several scans in the same
     * minute land on the exact same clock-face pixel (the tick position only
     * has minute resolution), so they're combined into one tick with a
     * summed count instead of stacking invisibly on top of each other — the
     * same shape the stock-decrease ticks already have (one number per
     * capture, not one tick per unit dropped). A scan is matched to the Kesei
     * row whose own part_no or a source part_no equals it.
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

        // [rowId][minute] => ['kanban' => count, 'at' => latest scan in that minute]
        $grouped = [];

        KeseiScan::whereIn('part_no', array_keys($rowByPartNo))
            ->whereBetween('scanned_at', [$from, $to])
            ->orderBy('scanned_at')
            ->get(['part_no', 'scanned_at'])
            ->each(function (KeseiScan $scan) use (&$grouped, $rowByPartNo) {
                $rowId = $rowByPartNo[$scan->part_no] ?? null;
                if ($rowId === null) {
                    return;
                }

                $minute = $this->clockMinute($scan->scanned_at);
                $grouped[$rowId][$minute]['kanban'] = ($grouped[$rowId][$minute]['kanban'] ?? 0) + 1;
                $grouped[$rowId][$minute]['at'] = $scan->scanned_at;
            });

        $events = array_fill_keys($keseiRows->pluck('id')->all(), []);

        foreach ($grouped as $rowId => $byMinute) {
            foreach ($byMinute as $minute => $group) {
                $events[$rowId][] = [
                    'minute' => $minute,
                    'kanban' => $group['kanban'],
                    'pcs' => $group['kanban'],
                    'time' => $group['at']->format('H:i'),
                    'at' => $group['at'],
                ];
            }
        }

        return $events;
    }
}
