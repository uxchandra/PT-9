<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\Part;
use App\Models\Pattern;
use App\Models\PatternBoard;
use App\Models\PatternGroupItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PatternImportTest extends TestCase
{
    use RefreshDatabase;

    private function authorizedUser(): User
    {
        (new RolePermissionSeeder)->run();

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_template_can_be_downloaded(): void
    {
        $response = $this->actingAs($this->authorizedUser())
            ->get(route('pattern-boards.import.template'));

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('template-import-pattern', $response->headers->get('content-disposition'));
    }

    public function test_guests_cannot_import(): void
    {
        $board = PatternBoard::create(['name' => 'A']);

        $response = $this->post(route('pattern-boards.import.store', $board), [
            'file' => UploadedFile::fake()->createWithContent('import.csv', "Machine,Item\n"),
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_import_creates_machines_parts_group_items_and_assignments(): void
    {
        $board = PatternBoard::create(['name' => 'A']);

        $csv = "Machine,Item,Jumlah Proses,Proses,loading_time,kanban,dandori\n"
            ."PT91,GA241-04750,9,2,40,10,10\n"
            ."PT92,GA241-04750,9,3,40,10,10\n"
            ."PT91,57453-BZ140,8,2,160,20,10\n";

        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        $response = $this->actingAs($this->authorizedUser())
            ->post(route('pattern-boards.import.store', $board), ['file' => $file]);

        $response->assertRedirect(route('pattern-boards.index', ['board' => $board->id]));

        // 2 machines, 2 parts, 2 group items (one per distinct part), 3 assignments.
        $this->assertSame(2, Machine::count());
        $this->assertSame(2, Part::count());
        $this->assertSame(2, PatternGroupItem::count());
        $this->assertSame(3, Pattern::count());

        $ga = Part::where('part_no', 'GA241-04750')->first();
        $groupItem = PatternGroupItem::where('pattern_board_id', $board->id)->where('part_id', $ga->id)->first();
        $this->assertSame(40, $groupItem->loading_time);
        $this->assertSame(9, $groupItem->jumlah_proses);
        $this->assertSame(10, $groupItem->total_kanban);
        $this->assertSame(10, $groupItem->dandori);
        $this->assertSame(1, $groupItem->urutan);

        $pt91 = Machine::where('name', 'PT91')->first();
        $pt92 = Machine::where('name', 'PT92')->first();

        $assignment91 = Pattern::where('machine_id', $pt91->id)->where('part_id', $ga->id)->first();
        $assignment92 = Pattern::where('machine_id', $pt92->id)->where('part_id', $ga->id)->first();

        $this->assertSame(2, $assignment91->proses);
        $this->assertSame(3, $assignment92->proses);
    }

    public function test_reimporting_a_part_with_different_group_values_does_not_overwrite_but_still_saves_assignment(): void
    {
        $board = PatternBoard::create(['name' => 'A']);
        $part = Part::create(['part_no' => 'GA241-04750']);
        $machineA = Machine::create(['name' => 'PT91']);
        $machineB = Machine::create(['name' => 'PT92']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 40,
            'jumlah_proses' => 9,
            'total_kanban' => 10,
            'dandori' => 10,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machineA->id,
            'part_id' => $part->id,
            'proses' => 2,
        ]);

        // Conflicting loading_time/kanban for the same part on a second machine.
        $csv = "Machine,Item,Jumlah Proses,Proses,loading_time,kanban,dandori\n"
            ."PT92,GA241-04750,9,3,999,999,999\n";

        $response = $this->actingAs($this->authorizedUser())
            ->post(route('pattern-boards.import.store', $board), [
                'file' => UploadedFile::fake()->createWithContent('import.csv', $csv),
            ]);

        $response->assertSessionHas('importMismatches');
        $this->assertNotEmpty(session('importMismatches'));

        $groupItem = PatternGroupItem::where('pattern_board_id', $board->id)->where('part_id', $part->id)->first();
        $this->assertSame(40, $groupItem->loading_time, 'group item values must not be overwritten by a later conflicting row');

        $assignmentB = Pattern::where('machine_id', $machineB->id)->where('part_id', $part->id)->first();
        $this->assertNotNull($assignmentB, 'the machine assignment must still be saved even when group values conflict');
        $this->assertSame(3, $assignmentB->proses);
    }

    public function test_rows_missing_machine_or_item_are_skipped(): void
    {
        $board = PatternBoard::create(['name' => 'A']);

        $csv = "Machine,Item,Jumlah Proses,Proses,loading_time,kanban,dandori\n"
            .",GA241-04750,9,2,40,10,10\n"
            ."PT91,,9,2,40,10,10\n"
            ."PT91,GA241-04750,9,2,40,10,10\n";

        $response = $this->actingAs($this->authorizedUser())
            ->post(route('pattern-boards.import.store', $board), [
                'file' => UploadedFile::fake()->createWithContent('import.csv', $csv),
            ]);

        $response->assertSessionHas('status');
        $this->assertStringContainsString('2 baris dilewati', session('status'));
        $this->assertSame(1, Pattern::count());
    }

    public function test_shift_column_is_imported_and_defaults_to_1_when_left_out(): void
    {
        $board = PatternBoard::create(['name' => 'A']);

        $csv = "Machine,Item,Jumlah Proses,Proses,loading_time,kanban,dandori,Shift\n"
            ."PT91,GA241-04750,9,2,40,10,10,1\n"
            ."PT91,57453-BZ140,8,2,160,20,10,2\n"
            ."PT91,NO-SHIFT-COL,1,1,10,1,0,\n"; // blank -> defaults to shift 1

        $response = $this->actingAs($this->authorizedUser())
            ->post(route('pattern-boards.import.store', $board), [
                'file' => UploadedFile::fake()->createWithContent('import.csv', $csv),
            ]);

        $response->assertRedirect(route('pattern-boards.index', ['board' => $board->id]));

        $shift1Part = Part::where('part_no', 'GA241-04750')->first();
        $shift2Part = Part::where('part_no', '57453-BZ140')->first();
        $blankShiftPart = Part::where('part_no', 'NO-SHIFT-COL')->first();

        $shift1GroupItem = PatternGroupItem::where('pattern_board_id', $board->id)->where('part_id', $shift1Part->id)->first();
        $shift2GroupItem = PatternGroupItem::where('pattern_board_id', $board->id)->where('part_id', $shift2Part->id)->first();
        $blankShiftGroupItem = PatternGroupItem::where('pattern_board_id', $board->id)->where('part_id', $blankShiftPart->id)->first();

        $this->assertSame(1, $shift1GroupItem->shift);
        $this->assertSame(2, $shift2GroupItem->shift);
        $this->assertSame(1, $blankShiftGroupItem->shift);

        $machine = Machine::where('name', 'PT91')->first();
        $shift2Assignment = Pattern::where('machine_id', $machine->id)->where('part_id', $shift2Part->id)->first();
        $this->assertSame(2, $shift2Assignment->shift);
    }

    public function test_same_part_can_have_separate_group_items_for_each_shift(): void
    {
        $board = PatternBoard::create(['name' => 'A']);

        // Same part+machine, once per shift, with different loading_time —
        // these must not be treated as conflicting/duplicate rows.
        $csv = "Machine,Item,Jumlah Proses,Proses,loading_time,kanban,dandori,Shift\n"
            ."PT91,GA241-04750,9,2,40,10,10,1\n"
            ."PT91,GA241-04750,9,5,55,15,10,2\n";

        $response = $this->actingAs($this->authorizedUser())
            ->post(route('pattern-boards.import.store', $board), [
                'file' => UploadedFile::fake()->createWithContent('import.csv', $csv),
            ]);

        $this->assertEmpty(session('importMismatches', []));

        $part = Part::where('part_no', 'GA241-04750')->first();
        $this->assertSame(2, PatternGroupItem::where('pattern_board_id', $board->id)->where('part_id', $part->id)->count());
        $this->assertSame(2, Pattern::where('pattern_board_id', $board->id)->where('part_id', $part->id)->count());

        $shift1GroupItem = PatternGroupItem::where('pattern_board_id', $board->id)->where('part_id', $part->id)->where('shift', 1)->first();
        $shift2GroupItem = PatternGroupItem::where('pattern_board_id', $board->id)->where('part_id', $part->id)->where('shift', 2)->first();

        $this->assertSame(40, $shift1GroupItem->loading_time);
        $this->assertSame(55, $shift2GroupItem->loading_time);
    }
}
