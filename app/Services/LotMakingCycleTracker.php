<?php

namespace App\Services;

use App\Models\LotMaking;
use App\Models\LotMakingCycle;
use App\Models\LotMakingScan;
use Illuminate\Support\Carbon;

/**
 * Tracks a Lot Making part's position within its current "lot cycle" —
 * independent of LotMakingPull's rolling stock-based demand. Every scan
 * advances a simple count of "how many scans have landed since the slots
 * were last completely full"; the moment that count reaches lot_produksi,
 * the cycle is logged as complete (feeding the Andon roller panel) and the
 * count implicitly starts over — nothing about past scans is touched, the
 * next lookup just only counts scans after the new completion.
 */
class LotMakingCycleTracker
{
    /**
     * How many scans have landed for $partNo since its last completed cycle
     * (or ever, if it has never completed one). Null when it isn't a Lot
     * Making part at all.
     */
    public function ticksSinceLastCycle(string $partNo): ?int
    {
        if (! LotMaking::whereHas('part', fn ($q) => $q->where('part_no', $partNo))->exists()) {
            return null;
        }

        return $this->countSince($partNo, $this->lastCompletionAt($partNo));
    }

    /**
     * Call right after recording a scan for a Lot Making part. Logs the
     * completed cycle when the tick count has reached lot_produksi.
     */
    public function checkForCompletion(string $partNo): void
    {
        $lotMaking = LotMaking::whereHas('part', fn ($q) => $q->where('part_no', $partNo))->first();

        if ($lotMaking === null || $lotMaking->lot_produksi === null || $lotMaking->lot_produksi <= 0) {
            return;
        }

        $ticks = $this->countSince($partNo, $this->lastCompletionAt($partNo));

        if ($ticks >= $lotMaking->lot_produksi) {
            LotMakingCycle::create([
                'part_no' => $partNo,
                'lot_produksi' => $lotMaking->lot_produksi,
                'completed_at' => now(),
            ]);
        }
    }

    private function lastCompletionAt(string $partNo): ?Carbon
    {
        // ->max() is a raw aggregate query — it returns a plain scalar from
        // the driver, not a cast Carbon instance.
        $value = LotMakingCycle::where('part_no', $partNo)->max('completed_at');

        return $value !== null ? Carbon::parse($value) : null;
    }

    private function countSince(string $partNo, ?Carbon $since): int
    {
        return LotMakingScan::where('part_no', $partNo)
            ->when($since !== null, fn ($q) => $q->where('scanned_at', '>', $since))
            ->count();
    }
}
