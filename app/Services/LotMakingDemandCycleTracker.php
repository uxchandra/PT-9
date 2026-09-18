<?php

namespace App\Services;

use App\Models\LotMaking;
use App\Models\LotMakingCycle;
use Illuminate\Support\Carbon;

/**
 * Demand-side twin of LotMakingCycleTracker — same "ticks since the slots
 * were last completely full" idea, but the ticks come straight from the raw
 * kanban-pull demand computed by LotMakingPull (stock decrease events from
 * the SOS feed), not from an operator's physical scan. Powers the Andon
 * "Lot Making 2" board, which mirrors Andon Kesei's stock-sourced board.
 *
 * Unlike the scan tracker — called once per live scan, so it only ever needs
 * to catch a single threshold crossing at a time — this one is driven by the
 * 15-minute stock snapshot tick (see CaptureStockSnapshot) and can see
 * several lots' worth of demand land in one pass. It walks the pulled kanban
 * events in order and logs one completion per lot_produksi crossed, each
 * stamped with the actual event time it happened at, not "now".
 *
 * Unlike the scan tracker, a completion here does NOT get thrown into the
 * shared Lot Making Planning queue — only LotMakingCycleTracker's scan-side
 * completions do. A physical lot finishing shows up as both an operator scan
 * and a stock decrease, so if both trackers fed the same Planning queue,
 * every completed lot would double up into two entries there (and in Andon
 * Kesei's Antrian (Fix Volume) panel, which reads straight from it) instead
 * of one. This tracker's own LotMakingCycle rows still exist purely to drive
 * the Lot Making 2 Andon board.
 */
class LotMakingDemandCycleTracker
{
    public function __construct(private LotMakingPull $pull)
    {
    }

    /**
     * Kanban pulled since the last completed demand cycle (or ever, if none
     * yet) — how full the "current" lot is right now. Null when $partNo
     * isn't a Lot Making part at all.
     */
    public function ticksSinceLastCycle(string $partNo): ?int
    {
        $lotMaking = LotMaking::with('part')->whereHas('part', fn ($q) => $q->where('part_no', $partNo))->first();

        if ($lotMaking === null) {
            return null;
        }

        $since = $this->lastCompletionAt($partNo);
        $pulled = (int) $this->pull->eventsSince($partNo, $since)->sum('kanban');
        $alreadySpent = $since !== null ? $this->completionsAt($partNo, $since) * ($lotMaking->lot_produksi ?? 0) : 0;

        return max(0, $pulled - $alreadySpent);
    }

    /**
     * Call after every stock snapshot capture. Logs one LotMakingCycle row
     * per lot_produksi worth of kanban pulled since the last completion —
     * catching up in one pass if several lots' worth landed since the last
     * check.
     */
    public function checkForCompletion(string $partNo): void
    {
        $lotMaking = LotMaking::whereHas('part', fn ($q) => $q->where('part_no', $partNo))->first();

        if ($lotMaking === null || $lotMaking->lot_produksi === null || $lotMaking->lot_produksi <= 0) {
            return;
        }

        $since = $this->lastCompletionAt($partNo);
        $events = $this->pull->eventsSince($partNo, $since);

        // eventsSince() returns the boundary event again in full (it's an
        // inclusive cutoff — see its doc comment), so start the running total
        // already short by whatever completions were logged against it last
        // time, instead of double-spending that event's kanban.
        $running = $since !== null
            ? -($this->completionsAt($partNo, $since) * $lotMaking->lot_produksi)
            : 0;

        foreach ($events as $event) {
            $running += $event['kanban'];

            while ($running >= $lotMaking->lot_produksi) {
                // Logged for the Lot Making 2 (demand) Andon board only — NOT
                // thrown into the shared LotMakingPlanning queue. A physical
                // lot completing shows up as both an operator scan and a
                // stock decrease, so if this also created a Planning row
                // (like LotMakingCycleTracker's scan-side completion does),
                // every completed lot would double up into two Antrian (Fix
                // Volume) / Lot Making Planning entries instead of one.
                LotMakingCycle::create([
                    'part_no' => $partNo,
                    'lot_produksi' => $lotMaking->lot_produksi,
                    'source' => LotMakingCycle::SOURCE_DEMAND,
                    'completed_at' => $event['at'],
                ]);

                $running -= $lotMaking->lot_produksi;
            }
        }
    }

    private function lastCompletionAt(string $partNo): ?Carbon
    {
        $value = LotMakingCycle::where('part_no', $partNo)
            ->where('source', LotMakingCycle::SOURCE_DEMAND)
            ->max('completed_at');

        return $value !== null ? Carbon::parse($value) : null;
    }

    /**
     * How many demand completions were logged at exactly $at — more than one
     * lot can complete from the same stock-snapshot event, and they all
     * share that event's timestamp (see checkForCompletion).
     */
    private function completionsAt(string $partNo, Carbon $at): int
    {
        return LotMakingCycle::where('part_no', $partNo)
            ->where('source', LotMakingCycle::SOURCE_DEMAND)
            ->where('completed_at', $at)
            ->count();
    }
}
