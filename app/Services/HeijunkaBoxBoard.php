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
 * kanban are waiting to be announced) — but not the instant it's captured.
 * It's HELD until the next shift starts (see holdForNextShift()): a
 * decrease during Shift 1 (07:10–20:04) only counts toward backlog once
 * Shift 2 begins at 20:05, and one during Shift 2 (20:05–07:09) only counts
 * once Shift 1 begins at 07:10 the next day — nothing releases mid-shift,
 * only at the following shift's own start. "Next shift" means the next
 * WORKING shift: a day with no Calendar entry (weekend/holiday — see
 * CalendarEntry::isWorkingDay()) has no shifts at all, so Friday night's
 * Shift 2 decreases release at Monday's 07:10, and that day's slots never
 * fire either (nobody is there to pull). Separately, this part's fixed
 * daily slots (see CELLS) are walked in order and matched to that backlog
 * FIFO — each slot with backlog available claims one unit; a slot with no
 * backlog at its own turn simply never fires, forever (it doesn't wait for
 * backlog to show up later — see fire()). A tick only ever lands on one of
 * the fixed slot times — never earlier, never a time in between, and never
 * at more than one slot — which is what "release di depan progress bar,
 * bukan ke belakang" means: it's bound to whichever slot happens to be next
 * once the (held) backlog is available, never backdated to when the stock
 * actually dropped or to before its shift's hold releases it.
 *
 * On the visual board specifically (not Perintah Pulling — see
 * firedEventsBatch()), that assignment is shown the INSTANT the hold
 * releases it, at its assigned slot column, even if the progress bar hasn't
 * reached that column yet (green/'pending' either way) — so staff can see
 * what's queued ahead of time instead of only once it's due. Perintah
 * Pulling still only turns a slot into actual scan demand once the progress
 * bar reaches it, so nothing gets asked for early — and it respects the
 * same shift hold, so an operator is never asked to pull something the
 * board hasn't released yet either.
 *
 * Nothing here hard-resets at 07:00 or after any fixed age: a delayed
 * (red, unpulled) tick stays on the live board — and stays Perintah
 * Pulling demand — until it's actually pulled. The only limit is the
 * HISTORY_DAYS lookback (see windowStart()) every stock decrease and scan
 * is read from, there purely to keep the query bounded.
 *
 * Same three-colour scheme as KeseiBoard's heijunka: a fired tick is green
 * while unscanned and within 15 minutes of firing, red once older than that
 * and still unscanned, or blue once matched to a scan (see scannedTimes()).
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

    /** First slot of each shift — see holdForNextShift(). */
    private const SHIFT_1_START = '07:10';

    private const SHIFT_2_START = '20:05';

    /**
     * How far back stock decreases and scans are read — same 8 days as
     * KeseiPart::FOLD_HISTORY_DAYS / LotMakingPull's own history floor. Not
     * a display cap: an unpulled tick stays until pulled; this only bounds
     * the query (a tick still unpulled after 8 days is long past mattering).
     */
    private const HISTORY_DAYS = 8;

    /**
     * Start of the lookback every box computation (board, Heikinka, and the
     * pulling services' own netting — see KeseiPull/LotMakingPull) reads
     * from, so they all agree on exactly which decreases and scans count.
     */
    public static function windowStart(Carbon $now): Carbon
    {
        return $now->copy()->subDays(self::HISTORY_DAYS);
    }

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
     * $asOf: null for the live board ("now", with the live board's own 3h-
     * after-pulled hide — see buildRow()'s $hideOldPulled), or a moment at
     * the END of some past production day for the date-picker history view
     * — every tick that day, red or blue, with none of the live hiding
     * rules (same reasoning as Heikinka's own history — see
     * ticksByPartNo()), and no "NOW" progress bar (there's no live progress
     * on a day that's already over).
     *
     * @return array<string, mixed>
     */
    public function data(?Carbon $asOf = null): array
    {
        $now = $asOf ?? now();
        $isHistory = $asOf !== null;
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
            ->map(function (Collection $schedulesInGroup, string $cycleIssue) use ($cycleGroups, $now, $isHistory, $dayStart) {
                $group = $cycleGroups->get($cycleIssue);

                // A past day's history only shows ticks fired ON that day —
                // the live board, by contrast, keeps every still-unpulled
                // tick however old (see the class doc).
                $rows = $schedulesInGroup
                    ->map(fn (HeijunkaBoxSchedule $schedule) => $this->buildRow($schedule, $now, hideOldPulled: ! $isHistory, showFrom: $isHistory ? $dayStart : null))
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
            'isHistory' => $isHistory,
            // The latest slot time (if any) already at/before now, for the
            // grid to highlight as "current" — same day-application logic
            // slotInstantsBetween() uses, so it lines up exactly with which
            // ticks have actually fired. A history view has no "now" on the
            // board at all — null keeps the progress bar off.
            'currentSlotTime' => $isHistory ? null : $this->currentSlotTime($now, $dayStart),
            // How far $now is between the current slot and the next one (0..1) —
            // lets the progress bar creep across a rest / shift-gap column
            // instead of sitting frozen at the slot before it.
            'progressFraction' => $isHistory ? 0.0 : $this->progressFraction($now, $dayStart),
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
     * $showFrom: when set, only ticks fired after it are returned (a past
     * day's history view, Heikinka's 24h clock face) — the FIFO scan
     * matching still runs over the full lookback first, so which ticks are
     * blue doesn't change, only which are shown.
     *
     * @return array<string, mixed>|null Null when the part isn't registered
     *                                    in Kesei or Lot Making at all (its
     *                                    schedule row has nothing to attach
     *                                    stock/scan data to).
     */
    private function buildRow(HeijunkaBoxSchedule $schedule, Carbon $now, bool $hideOldPulled = true, ?Carbon $showFrom = null): ?array
    {
        $part = $schedule->part;
        $kesei = KeseiPart::where('part_id', $part->id)->first();
        $lotMaking = $kesei === null ? LotMaking::where('part_id', $part->id)->first() : null;

        if ($kesei === null && $lotMaking === null) {
            return null;
        }

        $sources = $kesei?->sourcePartNos() ?? [$part->part_no];
        $qtyKbn = $part->qty_kbn;

        // Backlog keeps waiting for its slot, and a fired tick stays until
        // pulled, right across 07:00 and across days — see windowStart().
        $windowStart = self::windowStart($now);

        // Held to the next shift's own start (see holdForNextShift()) before
        // any of it counts toward backlog — a decrease captured mid-Shift 1
        // doesn't queue up until Shift 2 begins, and vice versa.
        $decreaseEvents = $this->holdForNextShift($this->decreaseEvents($sources, $qtyKbn, $windowStart, $now));
        $scannedTimes = $this->scannedTimes($sources, $windowStart, $now);

        // revealFuture: true — once a decrease is actually released (its
        // held shift-start checkpoint has arrived), the board shows it
        // against its assigned slot right away, not only once the progress
        // bar reaches that exact column (see fire()'s own doc). Perintah
        // Pulling (firedEventsBatch()) deliberately does NOT do this — a
        // pre-shown tick isn't due yet, so it must not become a scan demand
        // early.
        $ticks = $this->colourize($this->fire($schedule->slots, $decreaseEvents, $windowStart, $now, revealFuture: true), $now, $scannedTimes, $hideOldPulled);

        if ($showFrom !== null) {
            $ticks = array_values(array_filter($ticks, fn (array $t) => $t['at']->gte($showFrom)));
        }

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
            // nothing. Only the last 24h, though — Heikinka draws onto one
            // wrapping 24h clock face, so older days would alias onto it.
            $row = $this->buildRow($schedule, $asOf, hideOldPulled: false, showFrom: $asOf->copy()->subDay());

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
     * Every fixed slot instant for $slots across every WORKING production
     * day that touches [$windowStart, $now].
     *
     * @param  array<int, string>  $slots
     * @return Collection<int, array{time: string, at: Carbon}>
     */
    private function slotInstantsBetween(array $slots, Carbon $windowStart, Carbon $now): Collection
    {
        $instants = collect();
        $dayStart = \App\Models\CalendarEntry::productionDayStart($windowStart);
        $lastDayStart = \App\Models\CalendarEntry::productionDayStart($now);

        for (; $dayStart->lte($lastDayStart); $dayStart = $dayStart->copy()->addDay()) {
            // No shifts on a weekend/holiday — its slots don't exist, so
            // backlog waits for the next working day's instead of being
            // "pulled" by nobody.
            if (! \App\Models\CalendarEntry::isWorkingDay($dayStart)) {
                continue;
            }

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
     * @param  Collection<int, array{kanban: int, at: Carbon}>  $decreaseEvents  already
     *         run through holdForNextShift() — a decrease's 'at' here is its
     *         RELEASE moment, not necessarily when the stock actually dropped.
     * @return Collection<int, array{time: string, at: Carbon}>
     */
    private function fire(array $slots, Collection $decreaseEvents, Carbon $windowStart, Carbon $now, bool $revealFuture = false): Collection
    {
        // Slot instants must cover at least as far as the latest decrease's
        // own release moment — held-for-next-shift can push that up to a
        // day beyond $now's own production day (a Shift 2 decrease held to
        // the next day's Shift 1 start), further than the usual
        // $now-anchored range alone would reach.
        $latestEvent = $decreaseEvents->max('at');
        $slotsUntil = $latestEvent !== null && $latestEvent->gt($now) ? $latestEvent : $now;

        // Decreases listed before slots: sortBy() is stable, so when a held
        // decrease's release moment lands on the EXACT same instant as a
        // slot (the common case — release is a shift start, which is also
        // that shift's very first slot), the decrease's backlog counts
        // before that same-instant slot gets its turn, not after.
        $timeline = $decreaseEvents->map(fn (array $e) => ['kind' => 'decrease', 'at' => $e['at'], 'kanban' => $e['kanban']])
            ->concat($this->slotInstantsBetween($slots, $windowStart, $slotsUntil)->map(fn (array $s) => [...$s, 'kind' => 'slot']))
            ->sortBy('at')
            ->values();

        $backlog = 0;
        $fired = collect();

        foreach ($timeline as $event) {
            if ($event['at']->gt($now)) {
                if (! $revealFuture) {
                    break; // Perintah Pulling: stop exactly at the progress bar, whatever the kind.
                }

                if ($event['kind'] === 'decrease') {
                    // Not released yet (still held for the next shift, or a
                    // genuinely future event) — skip it without halting the
                    // walk; later future SLOT instants still need
                    // processing below for the pre-show.
                    continue;
                }
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
     * fired since windowStart() — i.e. per tick on the board, scanned or
     * not (the pulling services net scans off themselves, over that same
     * window). Parts with no schedule are simply absent from the
     * result. Same window AND the same held-for-next-shift delay as
     * the board itself (see buildRow()/holdForNextShift()), so a tick shown
     * on Heijunka always has matching pulling demand — an operator is never
     * asked to pull something the board hasn't released yet.
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
        $windowStart = self::windowStart($now);
        $keseiByPartId = KeseiPart::whereIn('part_id', $schedules->pluck('part_id'))->get()->keyBy('part_id');

        $sourcesBySchedule = $schedules->mapWithKeys(fn (HeijunkaBoxSchedule $s) => [
            $s->id => $keseiByPartId->get($s->part_id)?->sourcePartNos() ?: [$s->part->part_no],
        ]);

        $snapshots = StockSnapshot::whereIn('part_no', $sourcesBySchedule->flatten()->unique()->values()->all())
            ->whereBetween('captured_at', [$windowStart->copy()->subDay(), $now])
            ->orderBy('captured_at')
            ->get(['part_no', 'stock', 'captured_at'])
            // Grouped once up front — filtering the whole multi-day set per
            // schedule (Collection::whereIn() over every model) is what
            // dominates this method otherwise.
            ->groupBy('part_no');

        $result = [];

        foreach ($schedules as $schedule) {
            $sources = $sourcesBySchedule[$schedule->id];
            $ownSnapshots = collect($sources)
                ->flatMap(fn (string $partNo) => $snapshots->get($partNo, collect()))
                ->sortBy('captured_at')
                ->values();

            $events = $this->holdForNextShift($this->decreaseEvents(
                $sources,
                $schedule->part->qty_kbn,
                $windowStart,
                $now,
                $ownSnapshots
            ));

            $result[$schedule->part->part_no] = $this->fire($schedule->slots, $events, $windowStart, $now)
                ->map(fn (array $f) => ['kanban' => 1, 'at' => $f['at']])
                ->values();
        }

        return $result;
    }

    /**
     * Tags each fired tick the same way KeseiBoard::heijunkaVisualEvents()
     * does — see that method's doc for the full reasoning (grace period,
     * FIFO scan matching — a scan isn't recorded against a specific tick,
     * only that it happened, so each scan in turn is assumed to fulfil the
     * oldest tick already due — see matchScans()). No age cap on
     * an unscanned tick — it stays (red) until a scan matches it.
     *
     * $hideOldPulled (true on the live board, false for Heikinka's history —
     * see buildRow()/ticksByPartNo()) drops an already-scanned tick entirely
     * once its MATCHED scan (see $scannedTimes) is more than 3h old, instead
     * of letting it sit on the board as long as an outstanding one would.
     *
     * @param  Collection<int, array{time: string, at: Carbon}>  $fired
     * @param  Collection<int, Carbon>  $scannedTimes  oldest first
     * @return array<int, array{time: string, at: Carbon, heijunka_status: string}>
     */
    private function colourize(Collection $fired, Carbon $now, Collection $scannedTimes, bool $hideOldPulled = true): array
    {
        $fired = $fired->sortBy('at')->values();
        $matchedScanAt = $this->matchScans($fired, $scannedTimes);

        $result = [];

        foreach ($fired as $i => $event) {
            if (isset($matchedScanAt[$i])) {
                // Already pulled — the live board doesn't need to keep
                // showing it forever; it drops off 3h after it actually
                // turned blue, well before an outstanding tick would.
                if ($hideOldPulled && $matchedScanAt[$i]->diffInMinutes($now) > 3 * 60) {
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
     * FIFO scan → tick pairing: each scan, oldest first, fulfils the oldest
     * not-yet-fulfilled tick that was ALREADY DUE when it was scanned. A
     * scan with no such tick is surplus and fulfils nothing — never a tick
     * fired later, and never a pre-shown future one. (Pairing purely by
     * position used to let surplus scans — e.g. ones made against an older
     * version of the demand — swallow today's releases, including the
     * green pre-shown ones, which then vanished under the 3h hide.) Same
     * rule the pulling services net with — see KeseiPull::netDemand().
     *
     * @param  Collection<int, array{time: string, at: Carbon}>  $fired  oldest first
     * @param  Collection<int, Carbon>  $scannedTimes  oldest first
     * @return array<int, Carbon> $fired index => the scan that fulfilled it
     */
    private function matchScans(Collection $fired, Collection $scannedTimes): array
    {
        $matched = [];
        $next = 0;

        foreach ($scannedTimes as $scanAt) {
            if ($next < $fired->count() && $fired[$next]['at']->lte($scanAt)) {
                $matched[$next++] = $scanAt;
            }
        }

        return $matched;
    }

    /**
     * A decrease captured during Shift 1 (07:10–20:04) doesn't count toward
     * backlog until Shift 2 starts (20:05); one captured during Shift 2
     * (20:05–07:09) doesn't count until Shift 1 starts the next day (07:10)
     * — nothing releases mid-shift, only at the following shift's own
     * start. This is done by simply moving the event's own 'at' forward to
     * that release moment (see nextShiftStart()) before fire() ever sees
     * it — fire()'s backlog/slot matching is otherwise completely
     * unchanged, so a held unit still only ever fires at/after its release
     * moment, at one of the fixed slot times, exactly like any other.
     *
     * @param  Collection<int, array{kanban: int, at: Carbon}>  $events
     * @return Collection<int, array{kanban: int, at: Carbon}>
     */
    private function holdForNextShift(Collection $events): Collection
    {
        return $events->map(fn (array $e) => [...$e, 'at' => $this->nextShiftStart($e['at'])])->values();
    }

    /**
     * The next WORKING shift-start instant strictly after $at's own shift
     * window — Shift 1's window is [07:10, 20:05), Shift 2's is [20:05, next
     * 07:10). When that lands on a weekend/holiday (see
     * CalendarEntry::isWorkingDay()), it moves on to the first working
     * day's Shift 1 instead — e.g. Friday night's Shift 2 releases at
     * Monday 07:10, not Saturday 07:10 when nobody is there.
     */
    private function nextShiftStart(Carbon $at): Carbon
    {
        $dayStart = \App\Models\CalendarEntry::productionDayStart($at);
        $shift1Start = $this->shiftStartOn($dayStart, self::SHIFT_1_START);
        $shift2Start = $this->shiftStartOn($dayStart, self::SHIFT_2_START);

        if ($at->lt($shift1Start)) {
            // Still before Shift 1 even starts (the tail end of the
            // previous Shift 2) — released the moment Shift 1 begins.
            $release = $shift1Start;
        } elseif ($at->lt($shift2Start)) {
            // Within Shift 1's own window — released at Shift 2's start.
            $release = $shift2Start;
        } else {
            // Within Shift 2's window — released at Shift 1's start, next day.
            $release = $shift1Start->copy()->addDay();
        }

        // Bounded so a calendar with a long empty stretch can't loop forever.
        for ($i = 0; $i < 31; $i++) {
            $releaseDayStart = \App\Models\CalendarEntry::productionDayStart($release);

            if (\App\Models\CalendarEntry::isWorkingDay($releaseDayStart)) {
                break;
            }

            $release = $this->shiftStartOn($releaseDayStart->copy()->addDay(), self::SHIFT_1_START);
        }

        return $release;
    }

    private function shiftStartOn(Carbon $dayStart, string $time): Carbon
    {
        [$h, $m] = explode(':', $time);

        return $dayStart->copy()->startOfDay()->setTime((int) $h, (int) $m);
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
     * Every real scan timestamp for $sources in the window, oldest first —
     * merged across Kesei and Lot Making scans, since a given part_no only
     * ever belongs to one system or the other. colourize() pairs these FIFO
     * with the fired ticks already due at each scan (see matchScans() — a
     * scan isn't recorded against a specific tick, only that it happened),
     * so it knows
     * not just THAT a tick was pulled but WHEN — needed for the live
     * board's 3h-after-pulled hide (see colourize()'s $hideOldPulled).
     *
     * @param  array<int, string>  $sources
     * @return Collection<int, Carbon>
     */
    private function scannedTimes(array $sources, Carbon $since, Carbon $until): Collection
    {
        $kesei = KeseiScan::whereIn('part_no', $sources)->where('scanned_at', '>', $since)->where('scanned_at', '<=', $until)->pluck('scanned_at');
        $lotMaking = LotMakingScan::whereIn('part_no', $sources)->where('scanned_at', '>', $since)->where('scanned_at', '<=', $until)->pluck('scanned_at');

        return $kesei->concat($lotMaking)->sort()->values();
    }
}
