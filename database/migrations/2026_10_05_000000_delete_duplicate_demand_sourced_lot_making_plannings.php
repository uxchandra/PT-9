<?php

use App\Models\LotMakingCycle;
use App\Models\LotMakingPlanning;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * One-time cleanup for the duplicate-Antrian-row bug: before
 * LotMakingDemandCycleTracker stopped feeding the Lot Making Planning queue
 * (see that class's doc comment), every completed lot could get thrown into
 * `lot_making_plannings` TWICE — once from the operator scan side
 * (LotMakingCycleTracker) and once from the stock/demand side
 * (LotMakingDemandCycleTracker) — because a physical lot finishing shows up
 * as both signals for the same event. That showed up as duplicate rows in
 * Andon Kesei's Antrian (Fix Volume) panel and the Lot Making Planning page.
 *
 * This only removes the SAFE half of that duplication: a demand-sourced
 * planning row that has never been touched by staff (no proses step
 * assigned to a machine at all). A demand-sourced row that already has an
 * assignment is left alone — it's real, in-progress scheduling work, and
 * deleting it would silently break whatever's been built on top of it. Any
 * such row will need a manual look rather than an automatic one.
 *
 * The underlying `lot_making_cycles` row (source = demand) is NOT deleted —
 * it's still legitimate data driving the Lot Making 2 Andon board,
 * independent of whether it ever should have reached the Planning queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        $deletable = LotMakingPlanning::query()
            ->whereHas('lotMakingCycle', fn ($q) => $q->where('source', LotMakingCycle::SOURCE_DEMAND))
            ->whereDoesntHave('assignments')
            ->get(['id', 'part_id', 'lot', 'lot_making_cycle_id']);

        if ($deletable->isEmpty()) {
            return;
        }

        LotMakingPlanning::whereKey($deletable->pluck('id'))->delete();

        Log::info('Deleted duplicate demand-sourced Lot Making Planning rows', [
            'count' => $deletable->count(),
            'ids' => $deletable->pluck('id')->all(),
        ]);
    }

    public function down(): void
    {
        // Irreversible by design — the deleted rows were duplicates of real
        // data that still exists (the scan-sourced planning, and the
        // demand-sourced lot_making_cycles row), so there is nothing to
        // restore.
    }
};
