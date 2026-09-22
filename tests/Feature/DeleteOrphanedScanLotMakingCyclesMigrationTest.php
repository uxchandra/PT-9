<?php

namespace Tests\Feature;

use App\Models\LotMakingCycle;
use App\Models\LotMakingPlanning;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteOrphanedScanLotMakingCyclesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_10_14_000000_delete_orphaned_scan_sourced_lot_making_cycles.php');
        $migration->up();
    }

    public function test_deletes_a_scan_sourced_cycle_with_no_planning_row_at_all(): void
    {
        // The legacy orphan: scan-sourced, logged before lot_making_plannings
        // existed — no planning row, and never can have one.
        $orphan = LotMakingCycle::create(['part_no' => 'P1', 'lot_produksi' => 4, 'source' => LotMakingCycle::SOURCE_SCAN, 'completed_at' => now()]);

        $this->runMigration();

        $this->assertNull(LotMakingCycle::find($orphan->id));
    }

    public function test_a_scan_sourced_cycle_with_a_planning_row_survives(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        $cycle = LotMakingCycle::create(['part_no' => 'P1', 'lot_produksi' => 4, 'source' => LotMakingCycle::SOURCE_SCAN, 'completed_at' => now()]);
        LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 4, 'lot_making_cycle_id' => $cycle->id]);

        $this->runMigration();

        $this->assertNotNull(LotMakingCycle::find($cycle->id));
    }

    public function test_a_demand_sourced_cycle_with_no_planning_is_left_alone(): void
    {
        // Normal, by design — demand-sourced cycles never get a planning row.
        $cycle = LotMakingCycle::create(['part_no' => 'P1', 'lot_produksi' => 4, 'source' => LotMakingCycle::SOURCE_DEMAND, 'completed_at' => now()]);

        $this->runMigration();

        $this->assertNotNull(LotMakingCycle::find($cycle->id));
    }

    public function test_is_a_no_op_when_there_is_nothing_to_clean_up(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        $cycle = LotMakingCycle::create(['part_no' => 'P1', 'lot_produksi' => 4, 'source' => LotMakingCycle::SOURCE_SCAN, 'completed_at' => now()]);
        LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 4, 'lot_making_cycle_id' => $cycle->id]);

        $this->runMigration();
        $this->runMigration();

        $this->assertSame(1, LotMakingCycle::count());
    }
}
