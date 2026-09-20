<?php

namespace App\Services;

use App\Models\LotMaking;
use App\Models\LotMakingScan;
use App\Models\PatternGroupItem;
use App\Models\StockSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The pulling command for Lot Making parts. Which scanner card a part's
 * level (LotMaking::LEVELS) puts it on decides its MODE, exactly like it
 * does for a Kesei part on the same card (see KeseiPull::LOCATIONS):
 *
 *  - 'finish-goods' (demand) — the same rolling 15-minute stock-based
 *    target/scanned/remaining math as Kesei's own Finish Goods card (see
 *    demandRow), on top of which a manually-set "Perintah Pulling"
 *    (LotMaking::pulling_command) can seed/correct the target at any time —
 *    see demandRow() for exactly how. Lot Making has no closing time, so
 *    there's nothing to fold: the accumulation window is simply a fixed
 *    history floor, and it never resets on its own (a pulling_command does,
 *    once it ages out of that floor).
 *
 *    The stock decrease itself isn't added to the target the instant it's
 *    captured, though — same as Kesei, every part's own Lead Time per
 *    Kanban paces it through KeseiBoard::heijunkaEvents() first (see
 *    LotMaking::lt_per_kbn — null/0 is a no-op, so a part with none set
 *    behaves exactly as it always has). Only once the "now"/progress-bar on
 *    the merged Heijunka board has actually crossed a given kanban's paced
 *    release moment does it count toward `needed` at all.
 *  - 'store-3' (free) — no target at all, just an unlimited running scan
 *    count within that same fixed floor (see freeRow) — the part is free
 *    to be pulled and scanned any number of times, not tied to a stock
 *    decrease the way Finish Goods is.
 *
 * Either way this is entirely separate from LotMakingCycleTracker's own
 * slot-fill/roller side of scanning, which every Lot Making scan advances
 * regardless of level.
 */
class LotMakingPull
{
    private const HISTORY_DAYS = 8;

    public function __construct(private KeseiBoard $board) {}

    /**
     * Every Lot Making part whose level matches $level — one row per part,
     * merged onto that scanner card alongside its Kesei rows (see
     * ScannerController::location()).
     *
     * @return Collection<int, array{part_no: string, needed: ?int, scanned: int, remaining: ?int, last_update: ?string, done: bool}>
     */
    public function list(string $level): Collection
    {
        $lotMakings = LotMaking::with('part')->where('level', $level)->get()
            ->filter(fn (LotMaking $lm) => $lm->part?->part_no !== null);

        if ($lotMakings->isEmpty()) {
            return collect();
        }

        $floor = now()->copy()->subDays(self::HISTORY_DAYS);

        if (KeseiPull::isFree($level)) {
            return $lotMakings
                ->map(fn (LotMaking $lm) => $this->freeRow($lm->part->part_no, $floor))
                ->sortBy('done')
                ->values();
        }

        $now = now();
        $partNos = $lotMakings->map(fn (LotMaking $lm) => $lm->part->part_no)->unique()->values()->all();

        $snapshotsByPart = StockSnapshot::whereIn('part_no', $partNos)
            ->whereBetween('captured_at', [$floor->copy()->subDay(), $now])
            ->orderBy('captured_at')
            ->get(['part_no', 'stock', 'captured_at'])
            ->groupBy('part_no');

        $scansByPart = LotMakingScan::whereIn('part_no', $partNos)
            ->where('scanned_at', '>', $floor)
            ->orderBy('scanned_at')
            ->get(['part_no', 'scanned_at'])
            ->groupBy('part_no');

        return $lotMakings
            ->map(fn (LotMaking $lm) => $this->demandRow(
                $lm->part->part_no,
                $lm->part->qty_kbn,
                $floor,
                $snapshotsByPart->get($lm->part->part_no, collect()),
                $scansByPart->get($lm->part->part_no, collect())->pluck('scanned_at'),
                $lm->pulling_command,
                $lm->pulling_command_set_at,
                (float) ($lm->lt_per_kbn ?? 0)
            ))
            ->filter(fn (array $r) => $r['needed'] > 0 || $r['scanned'] > 0)
            ->sortBy('done')
            ->values();
    }

    /**
     * One row's figures computed on its own (no full-list rebuild) so a scan
     * stays fast — the same reasoning as KeseiPull::scanRow. Null when the
     * part isn't a Lot Making part, or its level doesn't match $level (the
     * scanner card being scanned from).
     *
     * @return array{part_no: string, needed: ?int, scanned: int, remaining: ?int, last_update: ?string, done: bool}|null
     */
    public function scanRow(string $partNo, string $level): ?array
    {
        $lm = LotMaking::with('part')
            ->where('level', $level)
            ->whereHas('part', fn ($q) => $q->where('part_no', $partNo))
            ->first();

        if ($lm === null || $lm->part === null) {
            return null;
        }

        $floor = now()->copy()->subDays(self::HISTORY_DAYS);

        if (KeseiPull::isFree($level)) {
            return $this->freeRow($partNo, $floor);
        }

        $now = now();

        $snapshots = StockSnapshot::where('part_no', $partNo)
            ->whereBetween('captured_at', [$floor->copy()->subDay(), $now])
            ->orderBy('captured_at')
            ->get(['part_no', 'stock', 'captured_at']);

        $scanTimes = LotMakingScan::where('part_no', $partNo)
            ->where('scanned_at', '>', $floor)
            ->orderBy('scanned_at')
            ->pluck('scanned_at');

        return $this->demandRow($partNo, $lm->part->qty_kbn, $floor, $snapshots, $scanTimes, $lm->pulling_command, $lm->pulling_command_set_at, (float) ($lm->lt_per_kbn ?? 0));
    }

    /**
     * @return array{part_no: string, needed: null, scanned: int, remaining: null, last_update: null, done: false}
     */
    private function freeRow(string $partNo, Carbon $floor): array
    {
        return [
            'part_no' => $partNo,
            'needed' => null,
            'scanned' => LotMakingScan::where('part_no', $partNo)->where('scanned_at', '>', $floor)->count(),
            'remaining' => null,
            'last_update' => null,
            'done' => false,
        ];
    }

    /**
     * Kanban-pull (stock decrease) events for $partNo at or after $since — or
     * within the usual history floor when $since is null. Shared by the
     * Andon "Lot Making 2" board and its cycle tracker so both read the exact
     * same numbers the scanner's pulling command would show, with nothing
     * scan-related involved.
     *
     * $since is meant to be a previous cycle completion's timestamp, and the
     * boundary is deliberately INCLUSIVE: one stock-snapshot tick can carry
     * more than one lot's worth of kanban, so the event that completion was
     * logged against may still have leftover kanban that didn't form a full
     * lot. Returning it again (in full) lets the caller net out exactly what
     * it already spent — see LotMakingDemandCycleTracker — instead of that
     * leftover silently vanishing because the event that carried it is never
     * looked at again.
     *
     * @return Collection<int, array{kanban: int, at: Carbon}>
     */
    public function eventsSince(string $partNo, ?Carbon $since): Collection
    {
        return $this->eventsSinceBatch([$partNo => $since])[$partNo] ?? collect();
    }

    /**
     * Batched form of eventsSince() — one stock-snapshot query for every part
     * asked for, each starting from its own cutoff, instead of one query per
     * part. $sinceByPart maps part_no => cutoff (null = use the history
     * floor for that part).
     *
     * @param  array<string, ?Carbon>  $sinceByPart
     * @return array<string, Collection<int, array{kanban: int, at: Carbon}>>
     */
    public function eventsSinceBatch(array $sinceByPart): array
    {
        if ($sinceByPart === []) {
            return [];
        }

        $partNos = array_keys($sinceByPart);
        $historyFloor = now()->copy()->subDays(self::HISTORY_DAYS);

        $qtyKbnByPart = LotMaking::with('part')->get()
            ->filter(fn (LotMaking $lm) => $lm->part !== null && in_array($lm->part->part_no, $partNos, true))
            ->keyBy(fn (LotMaking $lm) => $lm->part->part_no)
            ->map(fn (LotMaking $lm) => $lm->part->qty_kbn);

        $windowFloor = collect($sinceByPart)
            ->map(fn (?Carbon $since) => $since ?? $historyFloor)
            ->reduce(fn (?Carbon $carry, Carbon $floor) => $carry === null || $floor->lt($carry) ? $floor : $carry);

        $snapshotsByPart = StockSnapshot::whereIn('part_no', $partNos)
            ->whereBetween('captured_at', [$windowFloor->copy()->subDay(), now()])
            ->orderBy('captured_at')
            ->get(['part_no', 'stock', 'captured_at'])
            ->groupBy('part_no');

        $result = [];

        foreach ($sinceByPart as $partNo => $since) {
            $floor = $since ?? $historyFloor;
            $events = $this->decreaseEvents($qtyKbnByPart->get($partNo), $floor, $snapshotsByPart->get($partNo, collect()));

            // decreaseEvents() already only returns rows at/after $floor, so
            // once $floor is exactly $since this is already the inclusive
            // boundary described above — nothing further to filter.
            $result[$partNo] = $events;
        }

        return $result;
    }

    /**
     * @param  Collection<int, StockSnapshot>  $snapshots  chronological, may include seed rows before $from
     * @param  Collection<int, Carbon>  $scanTimes
     * @return array{part_no: string, needed: int, scanned: int, remaining: int, last_update: ?string, done: bool}
     */
    private function demandRow(string $partNo, ?string $qtyKbn, Carbon $from, Collection $snapshots, Collection $scanTimes, ?int $pullingCommand = null, ?Carbon $pullingCommandSetAt = null, float $ltPerKbn = 0.0): array
    {
        // Paced through the same heijunka release queue as Kesei (see the
        // class doc above) before anything else touches it — a part with no
        // Lead Time per Kanban set (the common case) is a no-op here, so
        // this changes nothing for it.
        $events = $this->board->heijunkaEvents($this->decreaseEvents($qtyKbn, $from, $snapshots), $ltPerKbn);

        // "Perintah Pulling" — same idea as KeseiPull::demandRow: a
        // manually-set target that REPLACES whatever had already
        // accumulated before it — decrease events at/before the moment it
        // was typed are dropped, only ones after it still count on top —
        // as long as it's still within the history floor (once it ages out
        // of $from, it's been dealt with along with the rest of that
        // window — see LotMaking::pulling_command).
        $baseline = 0;
        $useBaseline = $pullingCommandSetAt !== null && $pullingCommandSetAt->gte($from);

        if ($useBaseline) {
            $events = $events->filter(fn (array $e) => $e['at']->gt($pullingCommandSetAt));
            $baseline = $pullingCommand ?? 0;
        }

        $totalDecrease = (int) $events->sum('kanban');
        $lastUpdateAt = $events->pluck('at')->max();

        if ($useBaseline) {
            $lastUpdateAt = $lastUpdateAt === null ? $pullingCommandSetAt : $lastUpdateAt->max($pullingCommandSetAt);
        }

        if ($lastUpdateAt !== null) {
            $absorbed = $scanTimes->filter(fn (Carbon $t) => $t->lte($lastUpdateAt))->count();
            $scanned = $scanTimes->filter(fn (Carbon $t) => $t->gt($lastUpdateAt))->count();
        } else {
            $absorbed = 0;
            $scanned = $scanTimes->count();
        }

        $needed = max(0, $totalDecrease + $baseline - $absorbed);

        return [
            'part_no' => $partNo,
            'needed' => $needed,
            'scanned' => $scanned,
            'remaining' => max(0, $needed - $scanned),
            'last_update' => $lastUpdateAt?->format('H:i'),
            'done' => $needed > 0 && $scanned >= $needed,
        ];
    }

    /**
     * @param  Collection<int, StockSnapshot>  $snapshots
     * @return Collection<int, array{kanban: int, at: Carbon}>
     */
    private function decreaseEvents(?string $qtyKbn, Carbon $from, Collection $snapshots): Collection
    {
        $events = collect();
        $previous = null;

        foreach ($snapshots as $row) {
            $stock = (int) $row->stock;

            if ($row->captured_at->lt($from)) {
                $previous = $stock; // seed only — primes $previous, never an event itself.

                continue;
            }

            $decreasePcs = $previous !== null ? $previous - $stock : 0;

            if ($decreasePcs > 0) {
                $kanban = PatternGroupItem::calculateTotalKanban($decreasePcs, $qtyKbn);

                if ($kanban > 0) {
                    $events->push(['kanban' => $kanban, 'at' => $row->captured_at]);
                }
            }

            $previous = $stock;
        }

        return $events;
    }
}
