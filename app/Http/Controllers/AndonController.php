<?php

namespace App\Http\Controllers;

use App\Models\Part;
use App\Models\Pattern;
use App\Models\PatternBoard;
use App\Models\PatternGroupItem;
use App\Models\Rest;
use App\Models\StockSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AndonController extends Controller
{
    private const DAY_START = 7 * 60;

    // 05:00 the following day: shift 1 (07:00-16:00) + gap (16:00-20:00) + shift 2 (20:00-05:00).
    private const DAY_END = 24 * 60 + 5 * 60;

    private const SHIFT_GAP_START = 16 * 60;

    private const SHIFT_GAP_END = 20 * 60;

    private const SHIFT_GAP_LABEL = 'Pergantian Shift';

    private const PX_PER_MINUTE = 1.8;

    public function index(): View
    {
        $patternBoards = PatternBoard::withCount('patterns')->orderBy('name')->get();

        return view('andon.index', compact('patternBoards'));
    }

    public function show(PatternBoard $patternBoard): View
    {
        $patternBoards = PatternBoard::orderBy('name')->get();

        $groupItems = PatternGroupItem::where('pattern_board_id', $patternBoard->id)->get()->keyBy('part_id');

        // Ordered by id (creation order) rather than the part's Kelompok Pattern
        // "urutan", so each machine's sequence of blocks follows the order rows
        // were entered/imported for that machine, not a shared part-level order.
        $filteredPatterns = Pattern::where('pattern_board_id', $patternBoard->id)
            ->with(['machine', 'part'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Pattern $pattern) => $groupItems->has($pattern->part_id));

        $patterns = $filteredPatterns->groupBy('machine_id');

        // Part list for the Kosei stock view — one row per part assigned to this
        // board, independent of which machine(s) run it.
        $koseiParts = $filteredPatterns->pluck('part')->unique('id')->sortBy('name')->values();

        // Rest bands are shown for reference at their real clock time, but only the
        // shift-change gap actually pauses production — regular rests no longer
        // split/pause a running process, so loading-time bars stay unbroken.
        $restIntervals = $this->buildRestIntervals();
        $pauseIntervals = $restIntervals->filter(fn ($r) => $r['name'] === self::SHIFT_GAP_LABEL)->values();

        $timelineEnd = self::DAY_END;
        $rows = [];

        foreach ($patterns as $machineId => $machinePatterns) {
            $machine = $machinePatterns->first()->machine;
            $cursor = self::DAY_START;
            $blocks = [];

            foreach ($machinePatterns as $pattern) {
                $groupItem = $groupItems->get($pattern->part_id);
                $label = $pattern->part->name.' '.$pattern->proses.'/'.$groupItem->jumlah_proses;

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

            $filledThrough = max($cursor, self::DAY_END);
            foreach ($this->splitAroundRests($cursor, self::DAY_END, $pauseIntervals) as [$start, $end]) {
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

        return view('andon.show', [
            'patternBoard' => $patternBoard,
            'patternBoards' => $patternBoards,
            'rows' => $rows,
            'koseiParts' => $koseiParts,
            'restIntervals' => $restIntervals,
            'dayStart' => self::DAY_START,
            'timelineEnd' => $timelineEnd,
            'pxPerMinute' => self::PX_PER_MINUTE,
            'partColors' => $partColors,
        ]);
    }

    /**
     * Actual captured snapshots for one part (Kosei's "Timeline Stok" table),
     * matched to the source system by part name === stock_snapshots.part_no,
     * scoped to the current 07:00–06:00(+1) window. Only real snapshot rows are
     * returned (no filler slots), labeled with their real captured_at time so
     * movement between captures (every 5 min) is visible as it happens.
     */
    public function stockHistory(Part $part): JsonResponse
    {
        [$windowStart, $windowEnd] = $this->currentStockWindow();

        $points = StockSnapshot::where('part_no', $part->name)
            ->whereBetween('captured_at', [$windowStart, $windowEnd])
            ->orderBy('captured_at')
            ->get()
            ->map(fn (StockSnapshot $snapshot) => [
                'time' => $snapshot->captured_at->format('H:i'),
                'stock' => $snapshot->stock,
                'std_min' => $snapshot->std_min,
                'under_min' => $snapshot->stock < $snapshot->std_min,
            ]);

        return response()->json($points);
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

        foreach ($groupItems->sortBy('urutan') as $partId => $groupItem) {
            $colors[$partId] = $palette[$i % count($palette)];
            $i++;
        }

        return $colors;
    }

    /**
     * Rest windows from the database, plus the fixed shift-change gap, expressed
     * as minutes since 00:00 of the andon's start day. A rest that falls before
     * DAY_START (e.g. an early-morning break) is assumed to belong to the
     * following calendar day, since the timeline runs 07:00 through 05:00+1.
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
