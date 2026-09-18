<?php

namespace Tests\Feature;

use App\Models\LotMakingCycle;
use App\Models\LotMakingPlanning;
use App\Models\LotMakingPlanningAssignment;
use App\Models\Machine;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteDuplicateDemandLotMakingPlanningsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_10_05_000000_delete_duplicate_demand_sourced_lot_making_plannings.php');
        $migration->up();
    }

    public function test_deletes_only_untouched_demand_sourced_duplicates(): void
    {
        $part = Part::create(['part_no' => 'P1']);

        // The duplicate: demand-sourced, never assigned — safe to delete.
        $demandCycle = LotMakingCycle::create(['part_no' => 'P1', 'lot_produksi' => 4, 'source' => LotMakingCycle::SOURCE_DEMAND, 'completed_at' => now()]);
        $duplicate = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 4, 'lot_making_cycle_id' => $demandCycle->id]);

        // The real entry for the same lot: scan-sourced — must survive.
        $scanCycle = LotMakingCycle::create(['part_no' => 'P1', 'lot_produksi' => 4, 'source' => LotMakingCycle::SOURCE_SCAN, 'completed_at' => now()]);
        $scanPlanning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 4, 'lot_making_cycle_id' => $scanCycle->id]);

        // A demand-sourced planning that HAS been assigned — real scheduling
        // work already sits on it, must survive even though it's demand-sourced.
        $assignedDemandCycle = LotMakingCycle::create(['part_no' => 'P1', 'lot_produksi' => 4, 'source' => LotMakingCycle::SOURCE_DEMAND, 'completed_at' => now()]);
        $assignedPlanning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 4, 'lot_making_cycle_id' => $assignedDemandCycle->id]);
        LotMakingPlanningAssignment::create([
            'lot_making_planning_id' => $assignedPlanning->id,
            'proses' => 1,
            'machine_id' => Machine::create(['name' => 'M1'])->id,
            'shift' => 1,
        ]);

        $this->runMigration();

        $this->assertNull(LotMakingPlanning::find($duplicate->id));
        $this->assertNotNull(LotMakingPlanning::find($scanPlanning->id));
        $this->assertNotNull(LotMakingPlanning::find($assignedPlanning->id));

        // The demand LotMakingCycle rows themselves are untouched — still
        // needed by the Lot Making 2 Andon board.
        $this->assertNotNull(LotMakingCycle::find($demandCycle->id));
        $this->assertNotNull(LotMakingCycle::find($assignedDemandCycle->id));
    }

    public function test_is_a_no_op_when_there_is_nothing_to_clean_up(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        $scanCycle = LotMakingCycle::create(['part_no' => 'P1', 'lot_produksi' => 4, 'source' => LotMakingCycle::SOURCE_SCAN, 'completed_at' => now()]);
        LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 4, 'lot_making_cycle_id' => $scanCycle->id]);

        $this->runMigration();

        $this->assertSame(1, LotMakingPlanning::count());
    }
}
