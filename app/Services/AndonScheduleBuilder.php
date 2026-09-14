<?php

namespace App\Services;

use App\Models\LotMaking;
use App\Models\Machine;
use App\Models\Pattern;
use App\Models\PatternBoard;
use App\Models\PatternGroupItem;
use App\Models\Rest;
use Illuminate\Support\Collection;

/**
 * The machine/part schedule shared by the Andon Pattern board, Andon
 * Planning, and Andon Planning's table view — which parts run on which
 * machines and when, for both shifts, plus every idle stretch as a FREE TIME
 * block. Also used by Lot Making Planning to list each machine's actual free
 * windows (see LotMakingPlanningController) — always the exact same
 * computation the Andon board itself renders from, so a listed window is
 * guaranteed to really be free.
 */
class AndonScheduleBuilder
{
    public const DAY_START = 7 * 60;

    // 07:00 the following day — a full 24h window so nothing (least of all a
    // closing time, which can sit 4h before a part starts) gets clipped off
    // the right edge. Shift 1 (07:00-16:00) + gap (16:00-20:00) + shift 2
    // (20:00-06:00) all fit with an hour to spare.
    public const DAY_END = 7 * 60 + 24 * 60;

    public const SHIFT_GAP_START = 16 * 60;

    public const SHIFT_GAP_END = 20 * 60;

    public const SHIFT_GAP_LABEL = 'Pergantian Shift';

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: int, 2: Collection, 3: Collection, 4: array<int, string>, 5: Collection}
     */
    public function build(PatternBoard $patternBoard): array
    {
        $groupItems = PatternGroupItem::where('pattern_board_id', $patternBoard->id)->get();

        // A part can have its own group item per shift, so lookups during block
        // building are keyed by part_id+shift rather than part_id alone.
        $groupItemsByPartShift = $groupItems->keyBy(fn ($item) => $item->part_id.'-'.$item->shift);

        // Each machine's sequence of blocks follows the part's Kelompok Pattern
        // "urutan" (drag the rows on the Pattern page to reorder). sortBy() is
        // stable, and the query is id-ordered, so parts sharing an urutan keep
        // their creation order.
        $filteredPatterns = Pattern::where('pattern_board_id', $patternBoard->id)
            ->with(['machine', 'part'])
            ->orderBy('id')
            ->get()
            // Kept if there's a matching Kelompok Pattern entry, OR the
            // Pattern row carries its own loading_time/jumlah_proses (a Lot
            // Making Planning assignment — see the patterns table migration).
            ->filter(fn (Pattern $pattern) => $groupItemsByPartShift->has($pattern->part_id.'-'.$pattern->shift)
                || ($pattern->loading_time !== null && $pattern->jumlah_proses !== null))
            ->sortBy(fn (Pattern $p) => $groupItemsByPartShift->get($p->part_id.'-'.$p->shift)?->urutan ?? PHP_INT_MAX)
            ->values();

        $patterns = $filteredPatterns->groupBy('machine_id');

        // Live loading_time/dandori for parts scheduled without a Kelompok
        // Pattern entry (see buildShiftBlocks) — read fresh from Lot Making's
        // own Part record on every render, same as Kelompok Pattern's own
        // fields always are, so editing it there is reflected immediately
        // without needing to re-assign.
        $lotMakingByPart = LotMaking::whereIn('part_id', $filteredPatterns->pluck('part_id')->unique())
            ->get()
            ->keyBy('part_id');

        // Part list for the Kosei stock view — one row per part assigned to this
        // board, independent of which machine(s) or shift run it.
        $koseiParts = $filteredPatterns->pluck('part')->unique('id')->sortBy('part_no')->values();

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
                $machinePatterns->where('shift', 1), $groupItemsByPartShift, $lotMakingByPart, self::DAY_START, $pauseIntervals
            );
            [$shift2Blocks, $cursor2] = $this->buildShiftBlocks(
                $machinePatterns->where('shift', 2), $groupItemsByPartShift, $lotMakingByPart, self::SHIFT_GAP_END, $pauseIntervals
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

        // Every machine on the floor gets a row, not just the ones with a
        // pattern assigned on this board — an idle machine still needs to
        // show up, its whole row filled by the trailing-stretch pass below
        // (filledThrough starts at DAY_START, i.e. nothing placed yet).
        $machineIdsWithPatterns = $patterns->keys()->all();
        foreach (Machine::whereNotIn('id', $machineIdsWithPatterns)->get() as $idleMachine) {
            $rows[] = ['machine' => $idleMachine, 'blocks' => [], 'filledThrough' => self::DAY_START];
        }

        // Machine grouping above follows part order, not machine name, so rows would
        // otherwise land in an arbitrary order. Sort naturally (PT91, PT92, ..., PT100)
        // so the andon board reads top-to-bottom in a predictable sequence.
        usort($rows, fn ($a, $b) => strnatcasecmp($a['machine']->name, $b['machine']->name));

        // Stretch each machine's trailing free time to match the widest row so every row ends flush.
        foreach ($rows as &$row) {
            foreach ($this->splitAroundRests($row['filledThrough'], $timelineEnd, $pauseIntervals) as [$start, $end]) {
                $row['blocks'][] = ['type' => 'free', 'start' => $start, 'end' => $end, 'label' => 'FREE TIME'];
            }
        }
        unset($row);

        $partColors = $this->assignPartColors($groupItems);

        return [$rows, $timelineEnd, $groupItems, $koseiParts, $partColors, $restIntervals];
    }

    /**
     * Every FREE TIME window on $patternBoard's Andon timeline, one entry per
     * machine per contiguous gap — e.g. "PT91 (14:00 - 16:00)". Used by Lot
     * Making Planning to let staff pick an actual open slot directly instead
     * of guessing a shift: which shift a window belongs to is implicit in its
     * own clock time (a window landing past midnight is shift 2), so no
     * separate shift picker is needed on top of it.
     *
     * @return array<int, array{machine_id: int, machine_name: string, shift: int, start: int, end: int, label: string}>
     */
    public function freeWindows(PatternBoard $patternBoard): array
    {
        [$rows] = $this->build($patternBoard);

        $windows = [];

        foreach ($rows as $row) {
            foreach ($row['blocks'] as $block) {
                if ($block['type'] !== 'free') {
                    continue;
                }

                // Keyed so an identical (machine, start, end) window is never
                // listed twice — build()'s own trailing-stretch pass can add
                // the same gap a second time (harmless there: two 'free'
                // blocks drawn at the exact same position perfectly overlap
                // on Andon), but a picker showing the same option twice
                // would just be confusing.
                $key = $row['machine']->id.'|'.$block['start'].'|'.$block['end'];

                $windows[$key] = [
                    'machine_id' => $row['machine']->id,
                    'machine_name' => $row['machine']->name,
                    // A window starting before the shift-change gap is shift
                    // 1's own idle time; one starting at/after it (including
                    // ones that wrap past midnight) belongs to shift 2.
                    'shift' => $block['start'] < self::SHIFT_GAP_START ? 1 : 2,
                    'start' => $block['start'],
                    'end' => $block['end'],
                    'label' => sprintf('%s (%s - %s)', $row['machine']->name, $this->clockLabel($block['start']), $this->clockLabel($block['end'])),
                ];
            }
        }

        return array_values($windows);
    }

    /**
     * Minutes-since-DAY_START back to an ordinary wall-clock "HH:MM", wrapping
     * a window that lands past midnight (e.g. minute 1560 -> "02:00") rather
     * than showing an unreadable "26:00".
     */
    private function clockLabel(int $minute): string
    {
        $wrapped = $minute % (24 * 60);

        return sprintf('%02d:%02d', intdiv($wrapped, 60), $wrapped % 60);
    }

    /**
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
    private function buildShiftBlocks($patterns, $groupItemsByPartShift, $lotMakingByPart, int $windowStart, $pauseIntervals): array
    {
        $cursor = $windowStart;
        $blocks = [];

        foreach ($patterns as $pattern) {
            $groupItem = $groupItemsByPartShift->get($pattern->part_id.'-'.$pattern->shift);
            $lotMaking = $lotMakingByPart->get($pattern->part_id);

            // A normal Assignment Mesin always has a matching Kelompok
            // Pattern row — that's where these come from. A Lot Making
            // Planning assignment deliberately has none (see the patterns
            // table migration) and reads loading_time/dandori live from the
            // Lot Making part record instead, the same way Kelompok
            // Pattern's own fields are always read fresh rather than
            // snapshotted — editing either takes effect immediately, no
            // re-assign needed. Only jumlah_proses (typed once per
            // assignment) and total_kanban (a snapshot of that lot's size)
            // stay pinned to the Pattern row itself.
            $loadingTime = $groupItem?->loading_time ?? $lotMaking?->loading_time ?? $pattern->loading_time;
            $jumlahProses = $groupItem?->jumlah_proses ?? $pattern->jumlah_proses;
            $dandori = $groupItem?->dandori ?? $lotMaking?->dandori ?? $pattern->dandori ?? 0;
            $totalKanban = $groupItem?->total_kanban ?? $pattern->total_kanban;

            if ($loadingTime === null || $jumlahProses === null) {
                continue;
            }

            $label = $pattern->part->part_no.' '.$pattern->proses.'/'.$jumlahProses;

            // Andon Planning's "closing time" (4h before this part starts)
            // counts from the very start of this instance — its dandori if
            // it has one, otherwise its loading start.
            $productionStart = $cursor;

            if ($dandori > 0) {
                [$segments, $cursor] = $this->placeSegment($cursor, $dandori, $pauseIntervals);
                foreach ($segments as $i => [$start, $end]) {
                    $blocks[] = [
                        'type' => 'dandori',
                        'start' => $start,
                        'end' => $end,
                        'label' => (string) $dandori,
                        'showLabel' => $i === 0,
                    ];
                }
            }

            // Visualized rounded up to the nearest 5 minutes — a 121-minute
            // loading time reads as "125" wide on the board, 87 reads as "90"
            // — matching how the floor reads block widths. The stored value
            // itself, used for every real calculation, is untouched.
            $visualLoadingTime = (int) (ceil($loadingTime / 5) * 5);

            [$segments, $cursor] = $this->placeSegment($cursor, $visualLoadingTime, $pauseIntervals);
            foreach ($segments as $i => [$start, $end]) {
                $blocks[] = [
                    'type' => 'loading',
                    'start' => $start,
                    'end' => $end,
                    'label' => $label,
                    'kanban' => $totalKanban,
                    'showLabel' => $i === 0,
                    'part_id' => $pattern->part_id,
                    'pattern_id' => $pattern->id,
                    'production_start' => $productionStart,
                    'shift' => $pattern->shift,
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
