<?php

namespace App\Services;

use App\Models\HeijunkaBoxCycleGroup;
use App\Models\HeijunkaBoxSchedule;
use App\Models\KeseiPart;
use App\Models\KeseiScan;
use App\Models\LotMaking;
use App\Models\LotMakingScan;
use App\Models\PatternGroupItem;
use App\Models\StockSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The "Heijunka Box" board — a second, unrelated kind of heijunka from
 * KeseiBoard's own (LT/KBN-paced, computed) one. Every part on this board
 * has a fixed, pre-planned daily release timetable imported once from a
 * source spreadsheet (see HeijunkaBoxSchedule and its import migration),
 * not a pacing formula this class computes.
 *
 * The mechanism: a real stock decrease adds to a part's "backlog" (how many
 * kanban are waiting to be announced) the instant it's captured. Separately,
 * this part's fixed daily slots (see CELLS) are walked in order and matched
 * to that backlog FIFO — each slot with backlog still available claims one
 * unit; a slot with no backlog at its own turn simply never fires, forever
 * (it doesn't wait for backlog to show up later — see fire()). A tick only
 * ever lands on one of the fixed slot times — never earlier, never a time
 * in between, and never at more than one slot — which is what "release di
 * depan progress bar, bukan ke belakang" means: it's bound to whichever
 * slot happens to be next when the backlog is available, never backdated to
 * when the stock actually dropped.
 *
 * On the visual board specifically (not Perintah Pulling — see
 * firedEventsBatch()), that assignment is shown the INSTANT the decrease is
 * captured, at its assigned slot column, even if the progress bar hasn't
 * reached that column yet (green/'pending' either way) — so staff can see
 * what's queued ahead of time instead of only once it's due. Perintah
 * Pulling still only turns a slot into actual scan demand once the progress
 * bar reaches it, so nothing gets asked for early.
 *
 * The board loops on a rolling 24-hour window, NOT the 07:00 production-day
 * boundary — a stock decrease keeps contributing to backlog, and an unfired
 * slot keeps waiting for it, right across midnight/07:00 into the next day,
 * until exactly 24h after that decrease was captured (see buildRow()'s
 * $windowStart). This mirrors KeseiBoard's own looping-clock-face heijunka:
 * nothing here hard-resets at 07:00.
 *
 * Same three-colour scheme as KeseiBoard's heijunka: a fired tick is green
 * while unscanned and within 15 minutes of firing, red once older than that
 * and still unscanned, or blue once matched to a scan (see scannedCount()).
 * Because backlog itself already only spans the last 24h, every fired tick
 * is automatically within that same 24h — there's no separate cap to apply
 * on top, unlike KeseiBoard's own heijunka (which computes over a much
 * longer floor and caps display separately).
 *
 * A blue (already-pulled) tick doesn't linger on the LIVE board for the
 * full 24h though — it drops off 3h after it fired (see colourize()'s
 * $hideOldPulled), so the board stays focused on what's still outstanding
 * or recently done, not a growing pile of old pulls. Heikinka (the history
 * view, see ticksByPartNo()) deliberately does NOT apply this — it wants
 * every pull that ever happened on the day being viewed, however long ago.
 */
class HeijunkaBoxBoard
{
    /**
     * The fixed 32 daily slot times, in chronological order — imported once
     * from the source sheet (see the schedule import migration's own
     * SLOT_COLUMNS, which this must stay in sync with; re-extract from the
     * sheet's row 4/5 formulas if it's ever replaced) into
     * HeijunkaBoxSchedule.slots. Kept here too as the canonical column
     * order for the board's own header/grid rendering.
     */
    public const SLOTS = [
        '07:10', '07:40', '08:10', '08:40', '09:10', '09:40',
        '10:20', '10:50', '11:20', '11:50',
        '13:05', '13:35', '14:05', '14:35',
        '15:15', '15:45',
        '20:05', '20:35', '21:05', '21:35', '22:05', '22:35',
        '23:05', '23:35',
        '00:45', '01:15', '01:45', '02:15', '02:45',
        '03:55', '04:25', '04:55',
    ];

    /**
     * The full 38-cell grid the board renders left to right, one uniform-
     * width column per cell — 32 slot cells plus every gap between them
     * exactly as wide (visually) as the source sheet draws it: a 'rest' cell
     * for a short break, and one wide 'gap' cell for the long gap between
     * the two shifts. This is a grid, not a proportional clock — a real
     * heijunka box is a physical row of same-size slots, and the source
     * sheet itself renders it that way too (every column the same width).
     *
     * @return array<int, array{type: 'slot'|'rest'|'gap', time?: string}>
     */
    public static function cells(): array
    {
        $afterSlot = [
            '09:40' => 'rest', '11:50' => 'rest', '14:35' => 'rest',
            '15:45' => 'gap',
            '23:35' => 'rest', '02:45' => 'rest',
        ];

        $cells = [];

        foreach (self::SLOTS as $time) {
            $cells[] = ['type' => 'slot', 'time' => $time];

            if (isset($afterSlot[$time])) {
                $cells[] = ['type' => $afterSlot[$time]];
            }
        }

        return $cells;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $now = now();
        $dayStart = \App\Models\CalendarEntry::productionDayStart($now);

        // Sheet metadata (display order + the fixed "random number" row) for
        // whichever cycle_issue groups have it — see the grouping import
        // migration. Missing gracefully (empty random numbers, arbitrary
        // order) rather than hiding a group, so a schedule row is never
        // dropped just because this side-table hasn't been seeded for it.
        $cycleGroups = HeijunkaBoxCycleGroup::all()->keyBy('cycle_issue');

        $schedulesByGroup = HeijunkaBoxSchedule::with('part')
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (HeijunkaBoxSchedule $s) => $s->part !== null)
            ->groupBy('cycle_issue');

        $groups = $schedulesByGroup
            ->map(function (Collection $schedulesInGroup, string $cycleIssue) use ($cycleGroups, $now) {
                $group = $cycleGroups->get($cycleIssue);

                $rows = $schedulesInGroup
                    ->map(fn (HeijunkaBoxSchedule $schedule) => $this->buildRow($schedule, $now))
                    ->filter()
                    ->values();

                $subtotals = $this->subtotals($rows);

                return [
                    'cycle_issue' => $cycleIssue,
                    'random_numbers' => $group->random_numbers ?? [],
                    'cycle_labels' => $group->cycle_labels ?? [],
                    'rows' => $rows,
                    'subtotals' => $subtotals,
                    'sort_order' => $group->sort_order ?? PHP_INT_MAX,
                ];
            })
            ->filter(fn (array $g) => $g['rows']->isNotEmpty())
            ->sortBy('sort_order')
            ->values();

        return [
            'groups' => $groups,
            'cells' => self::cells(),
            'now' => $now,
            // The latest slot time (if any) already at/before now, for the
            // grid to highlight as "current" — same day-application logic
            // slotInstantsBetween() uses, so it lines up exactly with which
            // ticks have actually fired.
            'currentSlotTime' => $this->currentSlotTime($now, $dayStart),
            // How far $now is between the current slot and the next one (0..1) —
            // lets the progress bar creep across a rest / shift-gap column
            // instead of sitting frozen at the slot before it.
            'progressFraction' => $this->progressFraction($now, $dayStart),
            // The board's own grand-total footer row — every group's
            // subtotal (already a live tick count, see subtotals()) added
            // together per slot.
            'totals' => $this->grandTotals($groups),
        ];
    }

    /**
     * @param  Collection<int, array{subtotals: array<string, int>}>  $groups
     * @return array<string, int>
     */
    private function grandTotals(Collection $groups): array
    {
        $totals = array_fill_keys(self::SLOTS, 0);

        foreach ($groups as $group) {
            foreach ($group['subtotals'] as $time => $count) {
                $totals[$time] += $count;
            }
        }

        return $totals;
    }

    /**
     * The per-slot count of ticks actually showing on the board right now
     * for this group — i.e. how many of the lines rendered in each column
     * belong to this group, not the static plan they were scheduled from.
     * A tick already capped out of view (see colourize()'s 24h cutoff)
     * doesn't count here either, since it isn't on the board either.
     *
     * @param  Collection<int, array{ticks: array}>  $rows
     * @return array<string, int>
     */
    private function subtotals(Collection $rows): array
    {
        $totals = array_fill_keys(self::SLOTS, 0);

        foreach ($rows as $row) {
            foreach ($row['ticks'] as $tick) {
                $totals[$tick['time']] = ($totals[$tick['time']] ?? 0) + 1;
            }
        }

        return $totals;
    }

    private function progressFraction(Carbon $now, Carbon $dayStart): float
    {
        $previous = null;

        foreach (self::SLOTS as $time) {
            [$h, $m] = explode(':', $time);
            $at = $dayStart->copy()->startOfDay()->setTime((int) $h, (int) $m);

            if ($at->lt($dayStart)) {
                $at->addDay();
            }

            if ($at->gt($now)) {
                if ($previous === null) {
                    return 0.0;
                }

                return max(0.0, min(1.0, $previous->diffInSeconds($now) / max(1, $previous->diffInSeconds($at))));
            }

            $previous = $at;
        }

        return 0.0;
    }

    private function currentSlotTime(Carbon $now, Carbon $dayStart): ?string
    {
        $current = null;

        foreach (self::SLOTS as $time) {
            [$h, $m] = explode(':', $time);
            $at = $dayStart->copy()->startOfDay()->setTime((int) $h, (int) $m);

            if ($at->lt($dayStart)) {
                $at->addDay();
            }

            if ($at->gt($now)) {
                break;
            }

            $current = $time;
        }

        return $current;
    }

    /**
     * @return array<string, mixed>|null Null when the part isn't registered
     *                                    in Kesei or Lot Making at all (its
     *                                    schedule row has nothing to attach
     *                                    stock/scan data to).
     */
    private function buildRow(HeijunkaBoxSchedule $schedule, Carbon $now, bool $hideOldPulled = true): ?array
    {
        $part = $schedule->part;
        $kesei = KeseiPart::where('part_id', $part->id)->first();
        $lotMaking = $kesei === null ? LotMaking::where('part_id', $part->id)->first() : null;

        if ($kesei === null && $lotMaking === null) {
            return null;
        }

        $sources = $kesei?->sourcePartNos() ?? [$part->part_no];
        $qtyKbn = $part->qty_kbn;

        // Rolling 24h window — backlog from a stock decrease keeps waiting
        // for its slot (carrying right across 07:00 into the next day) for
        // up to 24h after it was captured, same as the display cap on the
        // ticks it eventually produces. Nothing resets at midnight/07:00.
        $windowStart = $now->copy()->subDay();

        $decreaseEvents = $this->decreaseEvents($sources, $qtyKbn, $windowStart, $now);
        $scannedCount = $this->scannedCount($sources, $windowStart, $now);

        // revealFuture: true — the board shows backlog against its assigned
        // slot the instant the stock decrease is captured, not only once the
        // progress bar reaches that column (see fire()'s own doc). Perintah
        // Pulling (firedEventsBatch()) deliberately does NOT do this — a
        // pre-shown tick isn't due yet, so it must not become a scan demand
        // early.
        $ticks = $this->colourize($this->fire($schedule->slots, $decreaseEvents, $windowStart, $now, revealFuture: true), $now, $scannedCount, $hideOldPulled);

        return [
            'id' => $schedule->id,
            'label' => $part->part_no,
            'cycle_issue' => $schedule->cycle_issue,
            'source' => $kesei !== null ? 'kesei' : 'lot-making',
            'ticks' => $ticks,
        ];
    }

    /**
     * Every Box-scheduled part's tick history as of $asOf (live "now", or
     * some moment in the past), keyed by part_no — filtered down to ONLY the
     * 'scanned' (blue/already-pulled) ones. This is what Heikinka (the
     * history view) draws: Heikinka is a record of pulling that actually
     * happened, so a pending/overdue (not-yet-pulled) tick doesn't belong in
     * it — only a completed pull does, at the exact slot time it pulled
     * against on the Heijunka board itself.
     *
     * @return array<string, array<int, array{time: string, at: Carbon, heijunka_status: string}>>
     */
    public function ticksByPartNo(Carbon $asOf): array
    {
        $result = [];

        foreach (HeijunkaBoxSchedule::with('part')->get() as $schedule) {
            if ($schedule->part === null) {
                continue;
            }

            // Heikinka wants the FULL history regardless of age — the live
            // board's own 3h-after-pulled hide (see colourize()) doesn't
            // apply here, or a day viewed hours later would show almost
            // nothing.
            $row = $this->buildRow($schedule, $asOf, hideOldPulled: false);

            if ($row !== null) {
                $result[$row['label']] = collect($row['ticks'])
                    ->filter(fn (array $t) => $t['heijunka_status'] === 'scanned')
                    ->values()
                    ->all();
            }
        }

        return $result;
    }

    /**
     * Every fixed slot instant for $slots across every production day that
     * touches [$windowStart, $now] — almost always exactly two days (the one
     * $windowStart falls in and the one $now falls in), since both the
     * window and a production day span 24h.
     *
     * @param  array<int, string>  $slots
     * @return Collection<int, array{time: string, at: Carbon}>
     */
    private function slotInstantsBetween(array $slots, Carbon $windowStart, Carbon $now): Collection
    {
        $instants = collect();
        $dayStart = \App\Models\CalendarEntry::productionDayStart($windowStart);
        $lastDayStart = \App\Models\CalendarEntry::productionDayStart($now);

        while ($dayStart->lte($lastDayStart)) {
            foreach ($slots as $time) {
                [$h, $m] = explode(':', $time);
                $at = $dayStart->copy()->startOfDay()->setTime((int) $h, (int) $m);

                // Slots after midnight (00:45 onward) land on the calendar
                // day AFTER $dayStart's own date — $dayStart is anchored at
                // 07:00, so anything before that on the clock face is
                // "tomorrow" relative to it.
                if ($at->lt($dayStart)) {
                    $at->addDay();
                }

                $instants->push(['time' => $time, 'at' => $at]);
            }

            $dayStart = $dayStart->copy()->addDay();
        }

        return $instants->sortBy('at')->values();
    }

    /**
     * Just the firing half of the backlog simulation described in the class
     * doc — every slot that fired between $windowStart and $now (plus,
     * when $revealFuture is true, every slot ASSIGNED backlog beyond $now
     * too — see below), chronological, with no colouring. This is what
     * Perintah Pulling reads (see firedEventsBatch()) and what colourize()
     * tags with a status.
     *
     * $revealFuture controls whether a slot can fire ahead of the progress
     * bar: false (Perintah Pulling) stops exactly at $now, so nothing not
     * yet due ever becomes scan demand early; true (the visual board) keeps
     * assigning backlog to the next open slot even past $now, so the moment
     * stock drops, staff can already see which column it's queued for
     * instead of waiting for the progress bar to reach it. Either way a
     * unit only ever fires at/after its own decrease was captured, and
     * never at more than one slot.
     *
     * @param  array<int, string>  $slots
     * @param  Collection<int, array{kanban: int, at: Carbon}>  $decreaseEvents
     * @return Collection<int, array{time: string, at: Carbon}>
     */
    private function fire(array $slots, Collection $decreaseEvents, Carbon $windowStart, Carbon $now, bool $revealFuture = false): Collection
    {
        $timeline = $this->slotInstantsBetween($slots, $windowStart, $now)
            ->map(fn (array $s) => [...$s, 'kind' => 'slot'])
            ->concat($decreaseEvents->map(fn (array $e) => ['kind' => 'decrease', 'at' => $e['at'], 'kanban' => $e['kanban']]))
            ->sortBy('at')
            ->values();

        $backlog = 0;
        $fired = collect();

        foreach ($timeline as $event) {
            // A decrease is always real, already-happened data — this only
            // guards against it in principle. A slot, though, can legitimately
            // sit past $now when $revealFuture allows it through.
            if ($event['at']->gt($now) && ($event['kind'] === 'decrease' || ! $revealFuture)) {
                break; // Nothing past the progress bar has happened yet.
            }

            if ($event['kind'] === 'decrease') {
                $backlog += $event['kanban'];

                continue;
            }

            if ($backlog > 0) {
                $fired->push(['time' => $event['time'], 'at' => $event['at']]);
                $backlog--;
            }
        }

        return $fired;
    }

    /**
     * Perintah Pulling source: for every part_no in $partNos that has a
     * Heijunka Box schedule, one {kanban: 1, at} event per slot that has
     * fired in the last 24h — i.e. per green/red tick currently on the
     * board, scanned or not (the pulling services net scans off
     * themselves). Parts with no schedule are simply absent from the
     * result. Same rolling window as the board itself (see buildRow()), so
     * a tick shown on Heijunka always has matching pulling demand.
     *
     * @param  array<int, string>  $partNos
     * @return array<string, Collection<int, array{kanban: int, at: Carbon}>>
     */
    public function firedEventsBatch(array $partNos): array
    {
        if ($partNos === []) {
            return [];
        }

        $schedules = HeijunkaBoxSchedule::with('part')
            ->whereHas('part', fn ($q) => $q->whereIn('part_no', $partNos))
            ->get();

        if ($schedules->isEmpty()) {
            return [];
        }

        $now = now();
        $windowStart = $now->copy()->subDay();
        $keseiByPartId = KeseiPart::whereIn('part_id', $schedules->pluck('part_id'))->get()->keyBy('part_id');

        $sourcesBySchedule = $schedules->mapWithKeys(fn (HeijunkaBoxSchedule $s) => [
            $s->id => $keseiByPartId->get($s->part_id)?->sourcePartNos() ?: [$s->part->part_no],
        ]);

        $snapshots = StockSnapshot::whereIn('part_no', $sourcesBySchedule->flatten()->unique()->values()->all())
            ->whereBetween('captured_at', [$windowStart->copy()->subDay(), $now])
            ->orderBy('captured_at')
            ->get(['part_no', 'stock', 'captured_at']);

        $result = [];

        foreach ($schedules as $schedule) {
            $events = $this->decreaseEvents(
                $sourcesBySchedule[$schedule->id],
                $schedule->part->qty_kbn,
                $windowStart,
                $now,
                $snapshots
            );

            $result[$schedule->part->part_no] = $this->fire($schedule->slots, $events, $windowStart, $now)
                ->map(fn (array $f) => ['kanban' => 1, 'at' => $f['at']])
                ->values();
        }

        return $result;
    }

    /**
     * Tags each fired tick the same way KeseiBoard::heijunkaVisualEvents()
     * does — see that method's doc for the full reasoning (grace period,
     * FIFO scan matching). No separate 24h cap needed here: fire() only
     * ever fires a slot between $windowStart (24h before $now) and $now, so
     * every tick it produces is already guaranteed to be within that
     * window.
     *
     * $hideOldPulled (true on the live board, false for Heikinka's history —
     * see buildRow()/ticksByPartNo()) drops an already-scanned tick entirely
     * once it's more than 3h past its own fired time, instead of letting it
     * sit on the board the same 24h an outstanding one would.
     *
     * @param  Collection<int, array{time: string, at: Carbon}>  $fired
     * @return array<int, array{time: string, at: Carbon, heijunka_status: string}>
     */
    private function colourize(Collection $fired, Carbon $now, int $scannedCount, bool $hideOldPulled = true): array
    {
        $fired = $fired->sortBy('at')->values();
        $scannedOfFired = min($scannedCount, $fired->count());

        $result = [];

        foreach ($fired as $i => $event) {
            if ($i < $scannedOfFired) {
                // Already pulled — the live board doesn't need to keep
                // showing it forever; it drops off 3h after its slot fired,
                // well before the 24h a still-outstanding tick gets.
                if ($hideOldPulled && $event['at']->diffInMinutes($now) > 3 * 60) {
                    continue;
                }

                $status = 'scanned';
            } elseif ($event['at']->gt($now)) {
                // Pre-shown (revealFuture) — assigned to its slot ahead of
                // the progress bar reaching it, so it's neither due nor
                // overdue yet.
                $status = 'pending';
            } else {
                $status = $event['at']->diffInMinutes($now) > 15 ? 'overdue' : 'pending';
            }

            $result[] = [...$event, 'heijunka_status' => $status];
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $sources
     * @return Collection<int, array{kanban: int, at: Carbon}>
     */
    private function decreaseEvents(array $sources, ?string $qtyKbn, Carbon $from, Carbon $to, ?Collection $preloaded = null): Collection
    {
        $snapshots = $preloaded !== null
            ? $preloaded->whereIn('part_no', $sources)
            : StockSnapshot::whereIn('part_no', $sources)
                ->whereBetween('captured_at', [$from->copy()->subDay(), $to])
                ->orderBy('captured_at')
                ->get(['part_no', 'stock', 'captured_at']);

        $rows = $snapshots
            ->groupBy(fn (StockSnapshot $s) => $s->captured_at->toDateTimeString());

        $events = collect();
        $previous = null;

        foreach ($rows as $timestamp => $snapshotsAtTime) {
            $stock = (int) $snapshotsAtTime->sum('stock');
            $at = Carbon::parse($timestamp);

            if ($at->lt($from)) {
                $previous = $stock;

                continue;
            }

            $decreasePcs = $previous !== null ? $previous - $stock : 0;

            if ($decreasePcs > 0) {
                $kanban = PatternGroupItem::calculateTotalKanban($decreasePcs, $qtyKbn);

                if ($kanban > 0) {
                    $events->push(['kanban' => $kanban, 'at' => $at]);
                }
            }

            $previous = $stock;
        }

        return $events;
    }

    /**
     * @param  array<int, string>  $sources
     */
    private function scannedCount(array $sources, Carbon $since, Carbon $until): int
    {
        $kesei = KeseiScan::whereIn('part_no', $sources)->whereBetween('scanned_at', [$since, $until])->where('scanned_at', '>', $since)->count();
        $lotMaking = LotMakingScan::whereIn('part_no', $sources)->whereBetween('scanned_at', [$since, $until])->where('scanned_at', '>', $since)->count();

        return $kesei + $lotMaking;
    }
}
