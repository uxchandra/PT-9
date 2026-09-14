<?php

namespace Tests\Feature;

use App\Models\LotMaking;
use App\Models\LotMakingAssignment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LotMakingAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function authorizedUser(): User
    {
        (new RolePermissionSeeder)->run();

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_the_assignment_machine_table_appears_on_the_lot_making_index_page(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 10, 'slot' => 1]);
        $machine = Machine::create(['name' => 'PT91']);
        LotMakingAssignment::create(['part_id' => $part->id, 'machine_id' => $machine->id, 'proses' => 1]);

        $html = $this->actingAs($this->authorizedUser())
            ->get(route('lot-makings.index'))->getContent();

        $this->assertStringContainsString('Assignment Machine', $html);
        $this->assertStringContainsString('PT91', $html);
        $this->assertStringContainsString('P1', $html);
    }

    public function test_an_assignment_can_be_created_for_a_lot_making_part(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 10, 'slot' => 1, 'jumlah_proses' => 3]);
        $machine = Machine::create(['name' => 'PT91']);

        $this->actingAs($this->authorizedUser())
            ->post(route('lot-making-assignments.store'), [
                'part_id' => $part->id,
                'machine_id' => $machine->id,
                'proses' => 2,
            ])
            ->assertRedirect(route('lot-makings.index'));

        $assignment = LotMakingAssignment::first();
        $this->assertSame($part->id, $assignment->part_id);
        $this->assertSame($machine->id, $assignment->machine_id);
        $this->assertSame(2, $assignment->proses);
    }

    public function test_a_part_not_registered_in_lot_making_is_rejected(): void
    {
        $part = Part::create(['part_no' => 'P1']); // no LotMaking record
        $machine = Machine::create(['name' => 'PT91']);

        $this->actingAs($this->authorizedUser())
            ->post(route('lot-making-assignments.store'), [
                'part_id' => $part->id,
                'machine_id' => $machine->id,
                'proses' => 1,
            ])
            ->assertSessionHasErrors('part_id');

        $this->assertSame(0, LotMakingAssignment::count());
    }

    public function test_proses_cannot_exceed_the_parts_jumlah_proses(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 10, 'slot' => 1, 'jumlah_proses' => 2]);
        $machine = Machine::create(['name' => 'PT91']);

        $this->actingAs($this->authorizedUser())
            ->post(route('lot-making-assignments.store'), [
                'part_id' => $part->id,
                'machine_id' => $machine->id,
                'proses' => 5,
            ])
            ->assertSessionHasErrors('proses');

        $this->assertSame(0, LotMakingAssignment::count());
    }

    public function test_the_same_proses_number_cannot_be_registered_twice_for_the_same_part(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 10, 'slot' => 1]);
        $machineA = Machine::create(['name' => 'PT91']);
        $machineB = Machine::create(['name' => 'PT92']);

        LotMakingAssignment::create(['part_id' => $part->id, 'machine_id' => $machineA->id, 'proses' => 1]);

        $this->actingAs($this->authorizedUser())
            ->post(route('lot-making-assignments.store'), [
                'part_id' => $part->id,
                'machine_id' => $machineB->id,
                'proses' => 1,
            ])
            ->assertSessionHasErrors('proses');

        $this->assertSame(1, LotMakingAssignment::count());
    }

    public function test_create_is_a_modal_on_the_lot_making_index_page_not_a_standalone_page(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 10, 'slot' => 1]);
        $machine = Machine::create(['name' => 'PT91']);
        LotMakingAssignment::create(['part_id' => $part->id, 'machine_id' => $machine->id, 'proses' => 1]);

        $html = $this->actingAs($this->authorizedUser())->get(route('lot-makings.index'))->getContent();

        $this->assertStringContainsString("open-modal', 'lot-making-assignment-create'", $html);
        $this->assertStringContainsString("== 'lot-making-assignment-create'", $html);
    }

    public function test_assignment_table_defaults_to_10_rows_per_page(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 10, 'slot' => 1]);

        foreach (range(1, 12) as $i) {
            $machine = Machine::create(['name' => "PT9{$i}"]);
            LotMakingAssignment::create(['part_id' => $part->id, 'machine_id' => $machine->id, 'proses' => $i]);
        }

        $assignments = $this->actingAs($this->authorizedUser())
            ->get(route('lot-makings.index'))
            ->viewData('assignments');

        $this->assertSame(10, $assignments->perPage());
        $this->assertTrue($assignments->hasPages());
    }

    public function test_an_assignment_can_be_updated_and_deleted(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 10, 'slot' => 1]);
        $machineA = Machine::create(['name' => 'PT91']);
        $machineB = Machine::create(['name' => 'PT92']);

        $assignment = LotMakingAssignment::create(['part_id' => $part->id, 'machine_id' => $machineA->id, 'proses' => 1]);

        $this->actingAs($this->authorizedUser())
            ->put(route('lot-making-assignments.update', $assignment), [
                'part_id' => $part->id,
                'machine_id' => $machineB->id,
                'proses' => 1,
            ])
            ->assertRedirect(route('lot-makings.index'));

        $this->assertSame($machineB->id, $assignment->fresh()->machine_id);

        $this->actingAs($this->authorizedUser())
            ->delete(route('lot-making-assignments.destroy', $assignment))
            ->assertRedirect(route('lot-makings.index'));

        $this->assertSame(0, LotMakingAssignment::count());
    }

    public function test_assignment_routes_require_the_manage_lot_making_permission(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 10, 'slot' => 1]);
        $machine = Machine::create(['name' => 'PT91']);
        $assignment = LotMakingAssignment::create(['part_id' => $part->id, 'machine_id' => $machine->id, 'proses' => 1]);

        $this->put(route('lot-making-assignments.update', $assignment), [])->assertRedirect(route('login'));

        (new RolePermissionSeeder)->run();
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $this->actingAs($staff)->put(route('lot-making-assignments.update', $assignment), [])->assertForbidden();

        // The create/edit forms themselves are modals on the (already
        // permission-gated) Lot Making index page, not standalone routes.
        $this->actingAs($this->authorizedUser())->get(route('lot-makings.index'))->assertOk();
    }
}
