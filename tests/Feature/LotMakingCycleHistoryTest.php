<?php

namespace Tests\Feature;

use App\Models\LotMakingCycle;
use App\Models\LotMakingPlanning;
use App\Models\LotMakingPlanningAssignment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LotMakingCycleHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        (new RolePermissionSeeder)->run();

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_history_page_requires_manage_lot_making_permission(): void
    {
        $this->get(route('lot-making-cycles.index'))->assertRedirect(route('login'));

        (new RolePermissionSeeder)->run();
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $this->actingAs($staff)->get(route('lot-making-cycles.index'))->assertForbidden();

        $this->actingAs($this->adminUser())->get(route('lot-making-cycles.index'))->assertOk();
    }

    public function test_lists_scan_sourced_cycles_newest_first_and_filters_by_part(): void
    {
        LotMakingCycle::create(['part_no' => 'AAA-111', 'lot_produksi' => 4, 'source' => LotMakingCycle::SOURCE_SCAN, 'completed_at' => Carbon::parse('2026-09-10 08:00')]);
        LotMakingCycle::create(['part_no' => 'BBB-222', 'lot_produksi' => 4, 'source' => LotMakingCycle::SOURCE_SCAN, 'completed_at' => Carbon::parse('2026-09-10 09:00')]);

        $this->actingAs($this->adminUser())
            ->get(route('lot-making-cycles.index'))
            ->assertOk()
            ->assertSee('AAA-111')
            ->assertSee('BBB-222')
            ->assertSeeInOrder(['BBB-222', 'AAA-111']); // newest first

        $this->actingAs($this->adminUser())
            ->get(route('lot-making-cycles.index', ['q' => 'AAA']))
            ->assertOk()
            ->assertSee('AAA-111')
            ->assertDontSee('BBB-222');
    }

    public function test_demand_sourced_cycles_are_left_off_this_history(): void
    {
        LotMakingCycle::create(['part_no' => 'DEM-1', 'lot_produksi' => 4, 'source' => LotMakingCycle::SOURCE_DEMAND, 'completed_at' => now()]);

        $this->actingAs($this->adminUser())
            ->get(route('lot-making-cycles.index'))
            ->assertOk()
            ->assertDontSee('DEM-1');
    }

    public function test_a_cycle_with_no_planning_row_shows_a_dash_status(): void
    {
        LotMakingCycle::create(['part_no' => 'ORPHAN-1', 'lot_produksi' => 4, 'source' => LotMakingCycle::SOURCE_SCAN, 'completed_at' => now()]);

        $html = $this->actingAs($this->adminUser())->get(route('lot-making-cycles.index'))->getContent();

        $this->assertStringContainsString('ORPHAN-1', $html);
        $this->assertStringNotContainsString('>Open<', $html);
        $this->assertStringNotContainsString('>In Progress<', $html);
        $this->assertStringNotContainsString('>Close<', $html);
    }

    public function test_status_is_open_when_no_step_has_an_assignment_yet(): void
    {
        $part = Part::create(['part_no' => 'OPEN-1']);
        \App\Models\LotMaking::create(['part_id' => $part->id, 'jumlah_proses' => 2]);
        $cycle = LotMakingCycle::create(['part_no' => 'OPEN-1', 'lot_produksi' => 4, 'source' => LotMakingCycle::SOURCE_SCAN, 'completed_at' => now()]);
        LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 4, 'lot_making_cycle_id' => $cycle->id]);

        $html = $this->actingAs($this->adminUser())->get(route('lot-making-cycles.index'))->getContent();

        $this->assertStringContainsString('OPEN-1', $html);
        $this->assertStringContainsString('>Open<', $html);
    }

    public function test_status_is_in_progress_when_some_but_not_all_steps_are_assigned_or_finished(): void
    {
        $part = Part::create(['part_no' => 'PROG-1']);
        \App\Models\LotMaking::create(['part_id' => $part->id, 'jumlah_proses' => 2]);
        $cycle = LotMakingCycle::create(['part_no' => 'PROG-1', 'lot_produksi' => 4, 'source' => LotMakingCycle::SOURCE_SCAN, 'completed_at' => now()]);
        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 4, 'lot_making_cycle_id' => $cycle->id]);
        $machine = Machine::create(['name' => 'M-1']);
        LotMakingPlanningAssignment::create(['lot_making_planning_id' => $planning->id, 'proses' => 1, 'machine_id' => $machine->id, 'shift' => 1]);

        $html = $this->actingAs($this->adminUser())->get(route('lot-making-cycles.index'))->getContent();

        $this->assertStringContainsString('PROG-1', $html);
        $this->assertStringContainsString('>In Progress<', $html);
        $this->assertStringContainsString('M-1', $html);
    }

    public function test_status_is_close_when_every_step_is_finished(): void
    {
        $part = Part::create(['part_no' => 'CLOSE-1']);
        \App\Models\LotMaking::create(['part_id' => $part->id, 'jumlah_proses' => 1]);
        $cycle = LotMakingCycle::create(['part_no' => 'CLOSE-1', 'lot_produksi' => 4, 'source' => LotMakingCycle::SOURCE_SCAN, 'completed_at' => now()]);
        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 4, 'lot_making_cycle_id' => $cycle->id]);
        $machine = Machine::create(['name' => 'M-2']);
        LotMakingPlanningAssignment::create(['lot_making_planning_id' => $planning->id, 'proses' => 1, 'machine_id' => $machine->id, 'shift' => 1, 'finished_at' => now()]);

        $html = $this->actingAs($this->adminUser())->get(route('lot-making-cycles.index'))->getContent();

        $this->assertStringContainsString('CLOSE-1', $html);
        $this->assertStringContainsString('>Close<', $html);
    }

    public function test_filters_by_date(): void
    {
        LotMakingCycle::create(['part_no' => 'D-OLD', 'lot_produksi' => 4, 'source' => LotMakingCycle::SOURCE_SCAN, 'completed_at' => Carbon::parse('2026-09-10 08:00')]);
        LotMakingCycle::create(['part_no' => 'D-NEW', 'lot_produksi' => 4, 'source' => LotMakingCycle::SOURCE_SCAN, 'completed_at' => Carbon::parse('2026-09-11 08:00')]);

        $this->actingAs($this->adminUser())
            ->get(route('lot-making-cycles.index', ['date' => '2026-09-10']))
            ->assertOk()
            ->assertSee('D-OLD')
            ->assertDontSee('D-NEW');
    }
}
