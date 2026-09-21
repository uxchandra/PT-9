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
 * this part's fixed daily slots (see CELLS) are walked in order — each one,
 * the moment the progress bar reaches it, "fires" (becomes a visible tick,
 * backlog drops by 1) IF there's still backlog to release; a slot with no
 * backlog at its own time simply never fires, forever (it doesn't wait for
 * backlog to show up later — see simulate()). A tick only ever lands on one
 * of the fixed slot times — never earlier, never a time in between — which
 * is what "release di depan progress bar, bukan ke belakang" means: it's
 * bound to appear at or after whichever slot happens to be next when the
 * backlog is available, never backdated to when the stock actually dropped.
 *
 * Same three-colour scheme as KeseiBoard's heijunka: a fired tick is green
 * while unscanned and within 15 minutes of firing, red once older than that
 * and still unscanned, or blue once matched to a scan (see
 * heijunkaScannedCounts()) — capped at 24h same as there, so an unscanned
 * tick doesn't linger forever and a part with heavy backlog doesn't alias
 * across days on what is, again, a looping clock face.
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
            ->map(function (Collection $schedulesInGroup, string $cycleIssue) use ($cycleGroups, $now, $dayStart) {
                $group = $cycleGroups->get($cycleIssue);

                $rows = $schedulesInGroup
                    ->map(fn (HeijunkaBoxSchedule $schedule) => $this->buildRow($schedule, $now, $dayStart))
                    ->filter()
                    ->values();

                return [
                    'cycle_issue' => $cycleIssue,
                    'random_numbers' => $group->random_numbers ?? [],
                    'rows' => $rows,
                    'subtotals' => $this->subtotals($rows),
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
            // simulate() uses for slot instants, so it lines up exactly
            // with which ticks have actually fired.
            'currentSlotTime' => $this->currentSlotTime($now, $dayStart),
        ];
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
    private function buildRow(HeijunkaBoxSchedule $schedule, Carbon $now, Carbon $dayStart): ?array
    {
        $part = $schedule->part;
        $kesei = KeseiPart::where('part_id', $part->id)->first();
        $lotMaking = $kesei === null ? LotMaking::where('part_id', $part->id)->first() : null;

        if ($kesei === null && $lotMaking === null) {
            return null;
        }

        $sources = $kesei?->sourcePartNos() ?? [$part->part_no];
        $qtyKbn = $part->qty_kbn;

        // One production day's worth of decreases — this board's backlog is
        // scoped to a single day (see the class doc: no cross-day carryover
        // in this first version), same window the fixed slots themselves
        // span (07:00 today through just before 07:00 tomorrow).
        $decreaseEvents = $this->decreaseEvents($sources, $qtyKbn, $dayStart, $dayStart->copy()->addDay());
        $scannedCount = $this->scannedCount($sources, $dayStart);

        $ticks = $this->simulate($schedule->slots, $decreaseEvents, $dayStart, $now, $scannedCount);

        return [
            'id' => $schedule->id,
            'label' => $part->part_no,
            'cycle_issue' => $schedule->cycle_issue,
            'source' => $kesei !== null ? 'kesei' : 'lot-making',
            'ticks' => $ticks,
        ];
    }

    /**
     * The backlog simulation described in the class doc, replayed from
     * scratch every render (a pure function of the day's stock decreases +
     * this part's fixed slots — nothing persisted).
     *
     * @param  array<int, string>  $slots  "H:i" times, chronological.
     * @param  Collection<int, array{kanban: int, at: Carbon}>  $decreaseEvents
     * @return array<int, array{time: string, at: Carbon, heijunka_status: string}>
     */
    private function simulate(array $slots, Collection $decreaseEvents, Carbon $dayStart, Carbon $now, int $scannedCount): array
    {
        $slotInstants = collect($slots)->map(function (string $time) use ($dayStart) {
            [$h, $m] = explode(':', $time);
            $at = $dayStart->copy()->startOfDay()->setTime((int) $h, (int) $m);

            // Slots after midnight (00:45 onward) land on the calendar day
            // AFTER $dayStart's own date — $dayStart is anchored at 07:00,
            // so anything before that on the clock face is "tomorrow"
            // relative to it.
            if ($at->lt($dayStart)) {
                $at->addDay();
            }

            return ['time' => $time, 'at' => $at];
        });

        $timeline = $slotInstants->map(fn (array $s) => [...$s, 'kind' => 'slot'])
            ->concat($decreaseEvents->map(fn (array $e) => ['kind' => 'decrease', 'at' => $e['at'], 'kanban' => $e['kanban']]))
            ->sortBy('at')
            ->values();

        $backlog = 0;
        $fired = collect();

        foreach ($timeline as $event) {
            if ($event['at']->gt($now)) {
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

        return $this->colourize($fired, $now, $scannedCount);
    }

    /**
     * Tags each fired tick the same way KeseiBoard::heijunkaVisualEvents()
     * does — see that method's doc for the full reasoning (grace period,
     * FIFO scan matching, 24h cap).
     *
     * @param  Collection<int, array{time: string, at: Carbon}>  $fired
     * @return array<int, array{time: string, at: Carbon, heijunka_status: string}>
     */
    private function colourize(Collection $fired, Carbon $now, int $scannedCount): array
    {
        $fired = $fired->sortBy('at')->values();
        $scannedOfFired = min($scannedCount, $fired->count());

        $result = [];

        foreach ($fired as $i => $event) {
            $minutesSinceFired = $event['at']->diffInMinutes($now);

            if ($i < $scannedOfFired) {
                if ($minutesSinceFired > 24 * 60) {
                    continue;
                }

                $status = 'scanned';
            } else {
                if ($minutesSinceFired > 24 * 60) {
                    continue;
                }

                $status = $minutesSinceFired > 15 ? 'overdue' : 'pending';
            }

            $result[] = [...$event, 'heijunka_status' => $status];
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $sources
     * @return Collection<int, array{kanban: int, at: Carbon}>
     */
    private function decreaseEvents(array $sources, ?string $qtyKbn, Carbon $from, Carbon $to): Collection
    {
        $rows = StockSnapshot::whereIn('part_no', $sources)
            ->whereBetween('captured_at', [$from->copy()->subDay(), $to])
            ->orderBy('captured_at')
            ->get(['part_no', 'stock', 'captured_at'])
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
    private function scannedCount(array $sources, Carbon $since): int
    {
        $kesei = KeseiScan::whereIn('part_no', $sources)->where('scanned_at', '>', $since)->count();
        $lotMaking = LotMakingScan::whereIn('part_no', $sources)->where('scanned_at', '>', $since)->count();

        return $kesei + $lotMaking;
    }
}
