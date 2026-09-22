<?php

use App\Models\LotMakingCycle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * One-time cleanup for a legacy data gap: a scan-sourced LotMakingCycle
 * normally always gets a matching LotMakingPlanning row created alongside it
 * (see LotMakingCycleTracker::checkForCompletion()), but any cycle logged
 * before the `lot_making_plannings` table existed (2026-09-25) has no such
 * row and never can. That orphan still shows up as an "Open" row on the
 * Andon Lot Making (scan) board's roller — since "no planning at all" reads
 * as still-open there — while never appearing in Andon Kesei's Antrian (Fix
 * Volume) panel, which only ever reads from `lot_making_plannings`. The two
 * panels disagreeing over a cycle nobody can act on anymore is confusing, so
 * this removes it from the Andon Lot Making board too.
 *
 * Scoped narrowly: only scan-sourced cycles with no linked planning row at
 * all. A demand-sourced cycle with no planning is normal (by design, see
 * LotMakingDemandCycleTracker) and must not be touched — it's still
 * legitimate data driving the Lot Making 2 Andon board.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphaned = LotMakingCycle::query()
            ->where('source', LotMakingCycle::SOURCE_SCAN)
            ->doesntHave('planning')
            ->get(['id', 'part_no', 'completed_at']);

        if ($orphaned->isEmpty()) {
            return;
        }

        LotMakingCycle::whereKey($orphaned->pluck('id'))->delete();

        Log::info('Deleted orphaned scan-sourced Lot Making cycles with no Planning row.', [
            'count' => $orphaned->count(),
            'ids' => $orphaned->pluck('id')->all(),
        ]);
    }

    public function down(): void
    {
        // Irreversible by design — the deleted rows were unrecoverable
        // orphans (no linked Planning row ever existed for them, and the
        // Andon board can't tell them apart from a real one once dropped).
    }
};
