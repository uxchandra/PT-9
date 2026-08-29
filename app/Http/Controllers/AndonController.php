<?php

namespace App\Http\Controllers;

use App\Models\Pattern;
use App\Models\PatternBoard;
use App\Models\PatternGroupItem;
use App\Models\Rest;
use App\Models\StockSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AndonController extends Controller
{
    private const DAY_START = 7 * 60;

    // 06:00 the following day: shift 1 (07:00-16:00) + gap (16:00-20:00) + shift 2 (20:00-06:00).
    private const DAY_END = 24 * 60 + 6 * 60;

    private const SHIFT_GAP_START = 16 * 60;

    private const SHIFT_GAP_END = 20 * 60;

    private const SHIFT_GAP_LABEL = 'Pergantian Shift';

    private const PX_PER_MINUTE = 1.8;

    public function index(): View
    {
        $patternBoards = PatternBoard::withCount('patterns')->orderBy('name')->get();

        return view('andon.index', compact('patternBoards'));
    }

    public function show(Request $request, PatternBoard $patternBoard): View|JsonResponse
    {
        $patternBoards = PatternBoard::orderBy('name')->get();

        $groupItems = PatternGroupItem::where('pattern_board_id', $patternBoard->id)->get();

        // A part can have its own group item per shift, so lookups during block
        // building are keyed by part_id+shift rather than part_id alone.
        $groupItemsByPartShift = $groupItems->keyBy(fn ($item) => $item->part_id.'-'.$item->shift);

        // Ordered by id (creation order) rather than the part's Kelompok Pattern
        // "urutan", so each machine's sequence of blocks follows the order rows
        // were entered/imported for that machine, not a shared part-level order.
        $filteredPatterns = Pattern::where('pattern_board_id', $patternBoard->id)
            ->with(['machine', 'part'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Pattern $pattern) => $groupItemsByPartShift->has($pattern->part_id.'-'.$pattern->shift));

        $patterns = $filteredPatterns->groupBy('machine_id');

        // Part list for the Kosei stock view — one row per part assigned to this
        // board, independent of which machine(s) or shift run it.
        $koseiParts = $filteredPatterns->pluck('part')->unique('id')->sortBy('part_no')->values();

        // Tick marks drawn directly on each part's Kosei row wherever its stock
        // dropped between two 5-minute captures, converted to kanban.
        $stockDecreaseEvents = $this->buildStockDecreaseEvents($koseiParts);

        // Timeline Stok: every part's stock side by side, sharing one time
        // column, instead of only showing whichever part was last clicked.
        $stockHistoryRows = $this->buildStockHistoryRows($koseiParts);

        // Rest bands are shown for reference at their real clock time, but only the
        // shift-change gap actually pauses production — regular rests no longer
        // split/pause a running process, so loading-time bars stay unbroken.
        $restIntervals = $this->buildRestIntervals();
        $pauseIntervals = $restIntervals->filter(fn ($r) => $r['name'] === self::SHIFT_GAP_LABEL)->values();

        $timelineEnd = self::DAY_END;
        $rows = [];

        foreach ($patterns as $machineId => $machinePatterns) {
            $machine = $machinePatterns->first()->machine;

            // Shift 1 and shift 2 are scheduled independently, each from its own
            // start of window, so shift 2 gets filled instead of always sitting
            // empty behind the shift-change gap.
            [$shift1Blocks, $cursor1] = $this->buildShiftBlocks(
                $machinePatterns->where('shift', 1), $groupItemsByPartShift, self::DAY_START, $pauseIntervals
            );
            [$shift2Blocks, $cursor2] = $this->buildShiftBlocks(
                $machinePatterns->where('shift', 2), $groupItemsByPartShift, self::SHIFT_GAP_END, $pauseIntervals
            );

            $blocks = array_merge($shift1Blocks, $shift2Blocks);

            foreach ($this->splitAroundRests($cursor1, self::SHIFT_GAP_START, $pauseIntervals) as [$start, $end]) {
                $blocks[] = ['type' => 'free', 'start' => $start, 'end' => $end, 'label' => 'FREE TIME'];
            }

            $filledThrough = max($cursor2, self::SHIFT_GAP_END);
            foreach ($this->splitAroundRests($cursor2, self::DAY_END, $pauseIntervals) as [$start, $end]) {
                $blocks[] = ['type' => 'free', 'start' => $start, 'end' => $end, 'label' => 'FREE TIME'];
            }

            foreach ($blocks as $block) {
                $timelineEnd = max($timelineEnd, $block['end']);
            }

            $rows[] = ['machine' => $machine, 'blocks' => $blocks, 'filledThrough' => $filledThrough];
        }

        // Machine grouping above follows part order, not machine name, so rows would
        // otherwise land in an arbitrary order. Sort naturally (PT91, PT92, ..., PT100)
        // so the andon board reads top-to-bottom in a predictable sequence.
        usort($rows, fn ($a, $b) => strnatcasecmp($a['machine']->name, $b['machine']->name));

        // Stretch each machine's trailing free time to match the widest row so every row ends flush.
        foreach ($rows as &$row) {
            foreach ($this->splitAroundRests($row['filledThrough'], $timelineEnd, $pauseIntervals) as [$start, $end]) {
                $row['blocks'][] = ['type' => 'free', 'start' => $start, 'end' => $end, 'label' => ''];
            }
        }
        unset($row);

        $partColors = $this->assignPartColors($groupItems);

        // Where "now" sits on the chart's own minute scale, so the browser can
        // auto-scroll each panel to the current time instead of starting at 07:00.
        [$windowStart] = $this->currentStockWindow();
        $nowMinute = self::DAY_START + (int) $windowStart->diffInMinutes(now());

        $viewData = [
            'patternBoard' => $patternBoard,
            'patternBoards' => $patternBoards,
            'rows' => $rows,
            'koseiParts' => $koseiParts,
            'restIntervals' => $restIntervals,
            'dayStart' => self::DAY_START,
            'timelineEnd' => $timelineEnd,
            'pxPerMinute' => self::PX_PER_MINUTE,
            'partColors' => $partColors,
            'stockDecreaseEvents' => $stockDecreaseEvents,
            'stockHistoryRows' => $stockHistoryRows,
            'nowMinute' => $nowMinute,
        ];

        if ($request->ajax()) {
            // Periodic refresh fetches this instead of reloading the page, so
            // the three panels update in place with no visible tab reload.
            return response()->json([
                'timeline' => view('andon._timeline', $viewData)->render(),
                'kosei' => view('andon._kosei-timeline', $viewData)->render(),
                'stockTimeline' => view('andon._stock-timeline', $viewData)->render(),
                'nowMinute' => $nowMinute,
                'serverTime' => now()->format('H:i:s'),
            ]);
        }

        return view('andon.show', $viewData);
    }

    /**
     * Timeline Stok, all parts at once: one row per captured_at timestamp
     * (all parts share the same captures since CaptureStockSnapshot writes
     * them in the same run), each row carrying every part's stock reading at
     * that moment keyed by part id — a part missing a reading for a given
     * timestamp (e.g. it dropped out of the source feed that cycle) is simply
     * absent from that row's values. Scoped to the same 07:00–06:00(+1)
     * window as the stock-decrease ticks.
     *
     * @return array<int, array{time: string, values: array<int, array{stock: int, under_min: bool}>}>
     */
    private function buildStockHistoryRows($koseiParts): array
    {
        if ($koseiParts->isEmpty()) {
            return [];
        }

        [$windowStart, $windowEnd] = $this->currentStockWindow();
        $partIdByPartNo = $koseiParts->pluck('id', 'part_no');

        $snapshots = StockSnapshot::whereIn('part_no', $partIdByPartNo->keys())
            ->whereBetween('captured_at', [$windowStart, $windowEnd])
            ->orderBy('captured_at')
            ->get();

        $rows = [];

        foreach ($snapshots->groupBy(fn (StockSnapshot $s) => $s->captured_at->toDateTimeString()) as $timestamp => $group) {
            $values = [];

            foreach ($group as $snapshot) {
                $partId = $partIdByPartNo->get($snapshot->part_no);

                if ($partId !== null) {
                    $values[$partId] = [
                        'stock' => $snapshot->stock,
                        'under_min' => $snapshot->stock < $snapshot->std_min,
                    ];
                }
            }

            // groupBy preserves first-seen order, and $snapshots was already
            // fetched ordered by captured_at, so this is already chronological.
            $rows[] = ['time' => Carbon::parse($timestamp)->format('H:i'), 'values' => $values];
        }

        return $rows;
    }

    /**
     * For each Kosei part, every moment its TD-process stock (StockSnapshot,
     * captured from Stock Part All every 5 minutes) dropped between one
     * capture and the next — the same window as stockHistory()'s "Timeline
     * Stok" — converted from pcs to kanban via lot÷qty_kbn rounded up, the
     * same rule as PatternGroupItem::calculateTotalKanban (so any decrease,
     * even smaller than one full Qty Kbn, still shows at least 1 tick). Only
     * decreases produce a tick; a flat or increasing (restock) reading is
     * ignored, and a part with no Qty Kbn set produces no tick either.
     *
     * @return array<int, array<int, array{minute: int, kanban: int, pcs: int, time: string}>>
     */
    private function buildStockDecreaseEvents($koseiParts): array
    {
        if ($koseiParts->isEmpty()) {
            return [];
        }

        [$windowStart, $windowEnd] = $this->currentStockWindow();
        $partNumbers = $koseiParts->pluck('part_no')->all();

        // One "seed" snapshot per part from just before the window, so the
        // first in-window reading still has something to compare against.
        // Fetched in bulk (2 queries total) rather than per part, since this
        // page reloads on its own every 60 seconds.
        $seedSnapshots = StockSnapshot::whereIn('part_no', $partNumbers)
            ->where('captured_at', '<', $windowStart)
            ->orderBy('part_no')
            ->orderByDesc('captured_at')
            ->get()
            ->unique('part_no')
            ->keyBy('part_no');

        $snapshotsByPart = StockSnapshot::whereIn('part_no', $partNumbers)
            ->whereBetween('captured_at', [$windowStart, $windowEnd])
            ->orderBy('captured_at')
            ->get()
            ->groupBy('part_no');

        $events = [];

        foreach ($koseiParts as $part) {
            $previous = $seedSnapshots->get($part->part_no);
            $partEvents = [];

            foreach ($snapshotsByPart->get($part->part_no, collect()) as $snapshot) {
                $decreasePcs = $previous ? $previous->stock - $snapshot->stock : 0;

                if ($decreasePcs > 0) {
                    $kanban = PatternGroupItem::calculateTotalKanban($decreasePcs, $part->qty_kbn);

                    if ($kanban > 0) {
                        $partEvents[] = [
                            'minute' => self::DAY_START + (int) $windowStart->diffInMinutes($snapshot->captured_at),
                            'kanban' => $kanban,
                            'pcs' => $decreasePcs,
                            'time' => $snapshot->captured_at->format('H:i'),
                        ];
                    }
                }

                $previous = $snapshot;
            }

            $events[$part->id] = $partEvents;
        }

        return $events;
    }

    /**
     * The Timeline Stok's fixed 07:00–06:00(+1) window. "Now" before 06:00
     * still belongs to the window that started yesterday at 07:00.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function currentStockWindow(): array
    {
        $now = now();
        $cutoff = $now->copy()->setTime(6, 0);
        $windowStart = $now->lt($cutoff) ? $now->copy()->subDay()->setTime(7, 0) : $now->copy()->setTime(7, 0);
        $windowEnd = $windowStart->copy()->addHours(23);

        return [$windowStart, $windowEnd];
    }

    /**
     * Assign each part a stable, visually distinct color (ordered by Kelompok
     * Pattern urutan) so the same part reads consistently across every machine row.
     * A part with a group item in both shifts only gets one color, taken from
     * whichever shift's urutan sorts first.
     *
     * @return array<int, string>
     */
    private function assignPartColors($groupItems): array
    {
        $palette = [
            '#f59e0b', '#3b82f6', '#10b981', '#ef4444', '#8b5cf6',
            '#ec4899', '#06b6d4', '#84cc16', '#f97316', '#6366f1',
            '#14b8a6', '#eab308', '#d946ef', '#0ea5e9', '#22c55e',
        ];

        $colors = [];
        $i = 0;

        foreach ($groupItems->sortBy('urutan') as $groupItem) {
            if (isset($colors[$groupItem->part_id])) {
                continue;
            }

            $colors[$groupItem->part_id] = $palette[$i % count($palette)];
            $i++;
        }

        return $colors;
    }

    /**
     * Place one shift's dandori+loading_time blocks back to back starting at
     * $windowStart, in $patterns' order. Returns the blocks plus the cursor
     * reached after the last one (or $windowStart unchanged if $patterns is empty).
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function buildShiftBlocks($patterns, $groupItemsByPartShift, int $windowStart, $pauseIntervals): array
    {
        $cursor = $windowStart;
        $blocks = [];

        foreach ($patterns as $pattern) {
            $groupItem = $groupItemsByPartShift->get($pattern->part_id.'-'.$pattern->shift);

            if (! $groupItem) {
                continue;
            }

            $label = $pattern->part->part_no.' '.$pattern->proses.'/'.$groupItem->jumlah_proses;

            if ($groupItem->dandori > 0) {
                [$segments, $cursor] = $this->placeSegment($cursor, $groupItem->dandori, $pauseIntervals);
                foreach ($segments as $i => [$start, $end]) {
                    $blocks[] = [
                        'type' => 'dandori',
                        'start' => $start,
                        'end' => $end,
                        'label' => (string) $groupItem->dandori,
                        'showLabel' => $i === 0,
                    ];
                }
            }

            [$segments, $cursor] = $this->placeSegment($cursor, $groupItem->loading_time, $pauseIntervals);
            foreach ($segments as $i => [$start, $end]) {
                $blocks[] = [
                    'type' => 'loading',
                    'start' => $start,
                    'end' => $end,
                    'label' => $label,
                    'kanban' => $groupItem->total_kanban,
                    'showLabel' => $i === 0,
                    'part_id' => $pattern->part_id,
                ];
            }
        }

        return [$blocks, $cursor];
    }

    /**
     * Rest windows from the database, plus the fixed shift-change gap, expressed
     * as minutes since 00:00 of the andon's start day. A rest that falls before
     * DAY_START (e.g. an early-morning break) is assumed to belong to the
     * following calendar day, since the timeline runs 07:00 through 06:00+1.
     */
    private function buildRestIntervals()
    {
        $restIntervals = Rest::orderBy('start_time')->get()->map(function (Rest $rest) {
            $start = $rest->start_time->hour * 60 + $rest->start_time->minute;
            $end = $rest->end_time->hour * 60 + $rest->end_time->minute;

            if ($start < self::DAY_START) {
                $start += 1440;
            }
            if ($end <= $start) {
                $end += 1440;
            }

            return ['start' => $start, 'end' => $end, 'name' => $rest->name];
        });

        $restIntervals->push([
            'start' => self::SHIFT_GAP_START,
            'end' => self::SHIFT_GAP_END,
            'name' => self::SHIFT_GAP_LABEL,
        ]);

        return $restIntervals->sortBy('start')->values();
    }

    /**
     * Place `$duration` minutes of working time starting at `$cursor`, jumping over
     * any interval in `$pauseIntervals` encountered so production pauses only for
     * those (the shift-change gap), resuming right after. Returns the (possibly
     * split) segments plus the new cursor.
     *
     * @return array{0: array<int, array{0: int, 1: int}>, 1: int}
     */
    private function placeSegment(int $cursor, int $duration, $pauseIntervals): array
    {
        $segments = [];
        $remaining = $duration;
        $pos = $cursor;
        $guard = 0;

        while ($remaining > 0 && $guard++ < 1000) {
            $activePause = $pauseIntervals->first(fn ($r) => $pos >= $r['start'] && $pos < $r['end']);

            if ($activePause) {
                $pos = $activePause['end'];

                continue;
            }

            $nextPauseStart = $pauseIntervals->first(fn ($r) => $r['start'] > $pos)['start'] ?? null;
            $available = $nextPauseStart !== null ? min($remaining, $nextPauseStart - $pos) : $remaining;

            if ($available <= 0) {
                $pos++;

                continue;
            }

            $segments[] = [$pos, $pos + $available];
            $pos += $available;
            $remaining -= $available;
        }

        return [$segments, $pos];
    }

    /**
     * Split [$start, $end) into the sub-ranges not covered by any pause interval,
     * so a "free time" fill never visually overlaps the shift-gap band.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private function splitAroundRests(int $start, int $end, $pauseIntervals): array
    {
        $segments = [];
        $pos = $start;

        foreach ($pauseIntervals as $rest) {
            if ($rest['end'] <= $pos || $rest['start'] >= $end) {
                continue;
            }

            if ($rest['start'] > $pos) {
                $segments[] = [$pos, $rest['start']];
            }

            $pos = max($pos, $rest['end']);
        }

        if ($pos < $end) {
            $segments[] = [$pos, $end];
        }

        return $segments;
    }
}
