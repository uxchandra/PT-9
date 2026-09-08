<?php

namespace App\Http\Controllers;

use App\Models\KeseiPart;
use App\Models\PatternGroupItem;
use App\Models\StockSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Standalone Kesei board — completely separate from the pattern-driven Andon
 * boards. It shows every row added in the Kesei menu with its stock timeline
 * for the current production day (07:00 → 07:00, rolling over at 06:30).
 *
 * A Kesei row's Timeline Stok is the SUM of its "stock_source" part_no(s)'
 * Stock Part All readings (falling back to the row's own part_no), so one
 * row can represent a family of part numbers that share a physical stock.
 */
class AndonKeseiController extends Controller
{
    private const DAY_START = 7 * 60;

    private const DAY_END = 7 * 60 + 24 * 60;

    private const PX_PER_MINUTE = 1.8;

    private const BOARD_SWITCH_MINUTE = 6 * 60 + 30;

    public function show(Request $request): View|JsonResponse
    {
        [$windowStart, $windowEnd] = $this->window();

        $keseiRows = KeseiPart::with(['part', 'patternBoards'])
            ->orderBy('urutan')
            ->orderBy('id')
            ->get()
            ->map(fn (KeseiPart $kesei) => [
                'id' => $kesei->id,
                'label' => $kesei->part?->part_no ?? '(part terhapus)',
                'qty_kbn' => $kesei->part?->qty_kbn,
                'sources' => $kesei->sourcePartNos(),
                'patterns' => $kesei->patternBoards->pluck('name')->all(),
                'closing_minute' => $this->closingChartMinute($kesei->closing_time),
                'closing_label' => $kesei->closing_time?->format('H:i'),
            ])
            ->filter(fn (array $row) => $row['sources'] !== [])
            ->values();

        [$seedStock, $stockByTime] = $this->loadStock($keseiRows, $windowStart, $windowEnd);

        $stockDecreaseEvents = $this->buildStockDecreaseEvents($keseiRows, $seedStock, $stockByTime, $windowStart);

        $viewData = [
            'keseiRows' => $keseiRows,
            'stockDecreaseEvents' => $stockDecreaseEvents,
            'closingKanban' => $this->buildClosingKanban($keseiRows, $stockDecreaseEvents),
            'stockHistoryRows' => $this->buildStockHistoryRows($keseiRows, $stockByTime),
            'dayStart' => self::DAY_START,
            'timelineEnd' => self::DAY_END,
            'pxPerMinute' => self::PX_PER_MINUTE,
            'productionLabel' => $windowStart->copy()->locale('id')->translatedFormat('l, d F Y'),
        ];

        if ($request->ajax()) {
            return response()->json([
                'timeline' => view('andon-kesei._timeline', $viewData)->render(),
                'closingTable' => view('andon-kesei._closing-table', $viewData)->render(),
                'stockTimeline' => view('andon-kesei._stock-timeline', $viewData)->render(),
                'serverTime' => now()->format('H:i:s'),
            ]);
        }

        return view('andon-kesei.show', $viewData);
    }

    /**
     * Current production day's 07:00 → 07:00(+1) window. Before 06:30 still
     * belongs to yesterday's window (same rule as the Andon board).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(): array
    {
        $now = now();
        $switch = $now->copy()->startOfDay()->addMinutes(self::BOARD_SWITCH_MINUTE);
        $date = ($now->lt($switch) ? $now->copy()->subDay() : $now)->toDateString();

        $windowStart = Carbon::parse($date)->setTime(7, 0);

        return [$windowStart, $windowStart->copy()->addHours(24)];
    }

    /**
     * A manually-entered closing time (wall clock) mapped onto the chart's
     * minute scale (07:00 = DAY_START ... 07:00 next day = DAY_END). A time
     * before 07:00 belongs to the tail of the window, so it's shifted a day
     * forward.
     */
    private function closingChartMinute(?Carbon $time): ?int
    {
        if ($time === null) {
            return null;
        }

        $minuteOfDay = $time->hour * 60 + $time->minute;
        $chart = $minuteOfDay >= self::DAY_START ? $minuteOfDay : $minuteOfDay + 1440;

        return min($chart, self::DAY_END);
    }

    /**
     * Accumulated kanban up to each row's closing time — the sum of every red
     * decrease tick at/before that minute. Rows with no closing time are
     * absent.
     *
     * @param  array<int, array<int, array{minute: int, kanban: int, pcs: int, time: string}>>  $stockDecreaseEvents
     * @return array<int, int>
     */
    private function buildClosingKanban(Collection $keseiRows, array $stockDecreaseEvents): array
    {
        $out = [];

        foreach ($keseiRows as $row) {
            if ($row['closing_minute'] === null) {
                continue;
            }

            $out[$row['id']] = collect($stockDecreaseEvents[$row['id']] ?? [])
                ->filter(fn (array $event) => $event['minute'] <= $row['closing_minute'])
                ->sum('kanban');
        }

        return $out;
    }

    /**
     * One snapshot query for every source part_no across all rows. Returns:
     *  - seed:      [part_no => stock] at the last capture before the window
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
     * Timeline Stok: one row per capture timestamp, each carrying every Kesei
     * row's summed stock keyed by kesei_part id. A row whose sources had no
     * reading at that timestamp is simply absent.
     *
     * @param  array<string, array{stock: array<string, int>, std_min: array<string, int>}>  $stockByTime
     * @return array<int, array{time: string, values: array<int, array{stock: int, under_min: bool}>}>
     */
    private function buildStockHistoryRows(Collection $keseiRows, array $stockByTime): array
    {
        if ($keseiRows->isEmpty()) {
            return [];
        }

        $rows = [];

        foreach ($stockByTime as $timestamp => $maps) {
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
     * two 5-minute captures within the window, converted to kanban (lot ÷
     * qty_kbn rounded up). Only decreases produce a tick.
     *
     * @param  array<string, int>  $seedStock
     * @param  array<string, array{stock: array<string, int>, std_min: array<string, int>}>  $stockByTime
     * @return array<int, array<int, array{minute: int, kanban: int, pcs: int, time: string}>>
     */
    private function buildStockDecreaseEvents(Collection $keseiRows, array $seedStock, array $stockByTime, Carbon $windowStart): array
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
                            'minute' => self::DAY_START + (int) $windowStart->diffInMinutes($at),
                            'kanban' => $kanban,
                            'pcs' => $decreasePcs,
                            'time' => $at->format('H:i'),
                        ];
                    }
                }

                $previous = $stock;
            }

            $events[$row['id']] = $rowEvents;
        }

        return $events;
    }
}
