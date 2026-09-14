<?php

namespace Tests\Feature;

use App\Models\CalendarEntry;
use App\Models\LotMaking;
use App\Models\LotMakingAssignment;
use App\Models\LotMakingCycle;
use App\Models\LotMakingPlanning;
use App\Models\LotMakingScan;
use App\Models\Machine;
use App\Models\Part;
use App\Models\Pattern;
use App\Models\PatternBoard;
use App\Models\PatternGroupItem;
use App\Models\StockSnapshot;
use App\Models\User;
use App\Services\AndonScheduleBuilder;
use App\Services\LotMakingCycleTracker;
use App\Services\LotMakingDemandCycleTracker;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LotMakingPlanningTest extends TestCase
{
    use RefreshDatabase;

    private function authorizedUser(): User
    {
        (new RolePermissionSeeder)->run();

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /**
     * Makes $board "today's running pattern" (see CalendarEntry) — Lot
     * Making Planning always auto-targets whichever board that resolves to,
     * never a manually-picked one. Carbon is pinned so the 07:00 rollover
     * (CalendarEntry::productionDayStart) can't flip the date depending on
     * what time the test actually runs at.
     */
    private function makeTodaysBoard(): PatternBoard
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $board = PatternBoard::create(['name' => 'A']);
        CalendarEntry::create(['date' => '2026-09-15', 'pattern_board_id' => $board->id]);

        return $board;
    }

    /**
     * A real, currently-free "start:end" window value for $machine's shift
     * 1 on $board, exactly as the Assign form's own JS would supply it.
     */
    private function anyFreeWindowValue(PatternBoard $board, int $machineId, int $shift): string
    {
        $window = collect(app(AndonScheduleBuilder::class)->freeWindows($board))
            ->first(fn (array $w) => $w['machine_id'] === $machineId && $w['shift'] === $shift);

        return $window['start'].':'.$window['end'];
    }

    public function test_a_completed_scan_cycle_throws_the_lot_into_planning(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 2, 'slot' => 1]);

        LotMakingScan::create(['part_no' => 'P1', 'raw' => 'x', 'scanned_at' => now()]);
        LotMakingScan::create(['part_no' => 'P1', 'raw' => 'x', 'scanned_at' => now()]);

        app(LotMakingCycleTracker::class)->checkForCompletion('P1');

        $planning = LotMakingPlanning::first();
        $this->assertNotNull($planning);
        $this->assertSame($part->id, $planning->part_id);
        $this->assertSame(2, $planning->lot);
        $this->assertFalse($planning->isAssigned());
        $this->assertFalse($planning->isFinished());
        $this->assertNotNull($planning->lot_making_cycle_id);
        $this->assertSame(LotMakingCycle::first()->id, $planning->lot_making_cycle_id);
    }

    public function test_a_completed_demand_cycle_also_throws_the_lot_into_planning(): void
    {
        $part = Part::create(['part_no' => 'P1', 'qty_kbn' => 1]);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 4, 'slot' => 2]);

        StockSnapshot::create(['part_no' => 'P1', 'stock' => 100, 'std_min' => 0, 'captured_at' => now()->subMinutes(20)]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 96, 'std_min' => 0, 'captured_at' => now()]);

        app(LotMakingDemandCycleTracker::class)->checkForCompletion('P1');

        $this->assertSame(1, LotMakingCycle::where('source', 'demand')->count());

        $planning = LotMakingPlanning::first();
        $this->assertNotNull($planning);
        $this->assertSame($part->id, $planning->part_id);
        $this->assertSame(4, $planning->lot);
        $this->assertSame(LotMakingCycle::first()->id, $planning->lot_making_cycle_id);
    }

    public function test_several_demand_completions_in_one_tick_each_throw_their_own_planning_row(): void
    {
        // Mirrors the multi-lot-per-tick scenario already covered for the
        // tracker itself (StockSnapshotTest) — every completion logged, from
        // a single stock-snapshot event or several, gets its own row here.
        $part = Part::create(['part_no' => 'P1', 'qty_kbn' => 1]);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 5, 'slot' => 2]);

        StockSnapshot::create(['part_no' => 'P1', 'stock' => 50, 'std_min' => 0, 'captured_at' => now()->subMinutes(20)]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 40, 'std_min' => 0, 'captured_at' => now()]); // -10 = 2 lots

        app(LotMakingDemandCycleTracker::class)->checkForCompletion('P1');

        $this->assertSame(2, LotMakingCycle::where('source', 'demand')->count());
        $this->assertSame(2, LotMakingPlanning::count());
    }

    public function test_index_requires_the_manage_lot_making_permission(): void
    {
        $this->get(route('lot-making-plannings.index'))->assertRedirect(route('login'));

        (new RolePermissionSeeder)->run();
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $this->actingAs($staff)->get(route('lot-making-plannings.index'))->assertForbidden();

        $this->actingAs($this->authorizedUser())->get(route('lot-making-plannings.index'))->assertOk();
    }

    public function test_index_defaults_to_the_open_tab_and_separates_all_3_statuses(): void
    {
        $partOpen = Part::create(['part_no' => 'OPEN-1']);
        $partProgress = Part::create(['part_no' => 'PROGRESS-1']);
        $partClosed = Part::create(['part_no' => 'CLOSED-1']);

        $board = PatternBoard::create(['name' => 'A']);
        $machine = Machine::create(['name' => 'PT91']);
        PatternGroupItem::create([
            'pattern_board_id' => $board->id, 'part_id' => $partProgress->id, 'shift' => 1,
            'urutan' => 1, 'lot' => 10, 'loading_time' => 10, 'jumlah_proses' => 1, 'total_kanban' => 1, 'dandori' => 0,
        ]);
        $pattern = Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $machine->id, 'part_id' => $partProgress->id, 'shift' => 1, 'proses' => 1]);

        $open = LotMakingPlanning::create(['part_id' => $partOpen->id, 'lot' => 10]);
        $inProgress = LotMakingPlanning::create([
            'part_id' => $partProgress->id, 'lot' => 10,
            'pattern_board_id' => $board->id, 'machine_id' => $machine->id, 'shift' => 1, 'proses' => 1,
            'pattern_id' => $pattern->id,
        ]);
        $closed = LotMakingPlanning::create(['part_id' => $partClosed->id, 'lot' => 10, 'finished_at' => now()]);

        $user = $this->authorizedUser();

        $openHtml = $this->actingAs($user)->get(route('lot-making-plannings.index'))->getContent();
        $this->assertStringContainsString('OPEN-1', $openHtml);
        $this->assertStringNotContainsString('PROGRESS-1', $openHtml);
        $this->assertStringNotContainsString('CLOSED-1', $openHtml);

        $progressHtml = $this->actingAs($user)->get(route('lot-making-plannings.index', ['status' => 'in_progress']))->getContent();
        $this->assertStringContainsString('PROGRESS-1', $progressHtml);
        $this->assertStringNotContainsString('OPEN-1', $progressHtml);
        $this->assertStringNotContainsString('CLOSED-1', $progressHtml);

        $closedHtml = $this->actingAs($user)->get(route('lot-making-plannings.index', ['status' => 'close']))->getContent();
        $this->assertStringContainsString('CLOSED-1', $closedHtml);
        $this->assertStringNotContainsString('OPEN-1', $closedHtml);
        $this->assertStringNotContainsString('PROGRESS-1', $closedHtml);

        $this->assertSame('open', $open->status);
        $this->assertSame('in_progress', $inProgress->fresh()->status);
        $this->assertSame('close', $closed->status);
    }

    public function test_assigning_creates_a_real_pattern_row_that_shows_up_on_andon(): void
    {
        $board = $this->makeTodaysBoard();
        $machine = Machine::create(['name' => 'PT91']);
        $part = Part::create(['part_no' => 'BZ020-KK010']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id, 'part_id' => $part->id, 'shift' => 1,
            'urutan' => 1, 'lot' => 10, 'loading_time' => 30, 'jumlah_proses' => 3,
            'total_kanban' => 1, 'dandori' => 0,
        ]);
        $assignment = LotMakingAssignment::create(['part_id' => $part->id, 'machine_id' => $machine->id, 'proses' => 2]);

        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);

        $this->actingAs($this->authorizedUser())
            ->put(route('lot-making-plannings.assign', $planning), [
                'lot_making_assignment_id' => $assignment->id,
                'shift' => 1,
                'window' => $this->anyFreeWindowValue($board, $machine->id, 1),
            ])
            ->assertRedirect(route('lot-making-plannings.index'));

        $planning->refresh();
        $this->assertTrue($planning->isAssigned());
        $this->assertNotNull($planning->pattern_id);

        $pattern = Pattern::find($planning->pattern_id);
        $this->assertSame($part->id, $pattern->part_id);
        $this->assertSame($machine->id, $pattern->machine_id);
        $this->assertSame(2, $pattern->proses);

        // Same label composition Assignment Mesin already uses — no extra
        // Andon rendering code needed for this to show up correctly.
        $html = $this->get("/andon/{$board->id}")->getContent();
        $this->assertStringContainsString('BZ020-KK010 2/3', $html);
    }

    public function test_assigning_a_part_not_registered_in_kelompok_pattern_requires_loading_time_on_its_lot_making_record(): void
    {
        // A Lot Making part floats between boards/machines day to day rather
        // than sitting on one fixed line, so it's never required to be
        // registered in Kelompok Pattern (that's for a permanent schedule).
        $board = $this->makeTodaysBoard();
        $machine = Machine::create(['name' => 'PT91']);
        $part = Part::create(['part_no' => 'P1']);
        // No PatternGroupItem for this part/board/shift, and no loading_time
        // set on its Lot Making record either.
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 10, 'slot' => 1]);
        $assignment = LotMakingAssignment::create(['part_id' => $part->id, 'machine_id' => $machine->id, 'proses' => 1]);

        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);

        $this->actingAs($this->authorizedUser())
            ->put(route('lot-making-plannings.assign', $planning), [
                'lot_making_assignment_id' => $assignment->id,
                'shift' => 1,
                'window' => $this->anyFreeWindowValue($board, $machine->id, 1),
            ])
            ->assertSessionHasErrors('lot_making_assignment_id');

        $this->assertFalse($planning->fresh()->isAssigned());
        $this->assertSame(0, Pattern::count());
    }

    public function test_assigning_a_part_not_registered_in_kelompok_pattern_reads_loading_time_and_dandori_from_the_lot_making_record(): void
    {
        $board = $this->makeTodaysBoard();
        $machine = Machine::create(['name' => 'PT91']);
        $part = Part::create(['part_no' => 'B121-97514', 'qty_kbn' => 5]);
        // No PatternGroupItem for this part/board/shift — the Pattern row
        // must carry its own scheduling data instead, sourced from here —
        // staff never types loading_time/dandori into the Assign form itself.
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 20, 'slot' => 1, 'loading_time' => 45, 'dandori' => 7, 'jumlah_proses' => 3]);
        $assignment = LotMakingAssignment::create(['part_id' => $part->id, 'machine_id' => $machine->id, 'proses' => 2]);

        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 20]);

        $this->actingAs($this->authorizedUser())
            ->put(route('lot-making-plannings.assign', $planning), [
                'lot_making_assignment_id' => $assignment->id,
                'shift' => 1,
                'window' => $this->anyFreeWindowValue($board, $machine->id, 1),
            ])
            ->assertRedirect(route('lot-making-plannings.index'));

        $planning->refresh();
        $this->assertTrue($planning->isAssigned());
        $this->assertSame(45, $planning->loading_time);

        $pattern = Pattern::find($planning->pattern_id);
        $this->assertSame(45, $pattern->loading_time);
        $this->assertSame(3, $pattern->jumlah_proses);
        $this->assertSame(2, $pattern->proses);
        $this->assertSame(7, $pattern->dandori);
        // 20 lot / 5 qty_kbn = 4 kanban.
        $this->assertSame(4, $pattern->total_kanban);

        // The block genuinely renders on Andon — no Kelompok Pattern entry
        // needed at all.
        $html = $this->get("/andon/{$board->id}")->getContent();
        $this->assertStringContainsString('B121-97514 2/3', $html);
    }

    public function test_editing_loading_time_after_assignment_is_reflected_on_andon_without_reassigning(): void
    {
        $board = $this->makeTodaysBoard();
        $machine = Machine::create(['name' => 'PT91']);
        $part = Part::create(['part_no' => 'P1', 'qty_kbn' => 1]);
        $lotMaking = LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 10, 'slot' => 1, 'loading_time' => 40, 'dandori' => 0, 'jumlah_proses' => 1]);
        $assignment = LotMakingAssignment::create(['part_id' => $part->id, 'machine_id' => $machine->id, 'proses' => 1]);

        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);

        $this->actingAs($this->authorizedUser())
            ->put(route('lot-making-plannings.assign', $planning), [
                'lot_making_assignment_id' => $assignment->id,
                'shift' => 1,
                'window' => $this->anyFreeWindowValue($board, $machine->id, 1),
            ]);

        // 40min * 1.8px/min = 72px before the edit.
        $before = $this->get("/andon/{$board->id}")->getContent();
        $this->assertStringContainsString('width: 72px', $before);

        // Now edit the Lot Making part's loading_time — no re-assign happens.
        $lotMaking->update(['loading_time' => 90]);

        // 90min * 1.8px/min = 162px — reflected immediately, live.
        $after = $this->get("/andon/{$board->id}")->getContent();
        $this->assertStringContainsString('width: 162px', $after);
        $this->assertStringNotContainsString('width: 72px', $after);
    }

    public function test_assigning_is_rejected_when_proses_exceeds_jumlah_proses(): void
    {
        $board = $this->makeTodaysBoard();
        $machine = Machine::create(['name' => 'PT91']);
        $part = Part::create(['part_no' => 'P1']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id, 'part_id' => $part->id, 'shift' => 1,
            'urutan' => 1, 'lot' => 10, 'loading_time' => 30, 'jumlah_proses' => 2,
            'total_kanban' => 1, 'dandori' => 0,
        ]);
        // Created directly (bypassing LotMakingAssignmentController's own
        // validation) to exercise the controller's defense-in-depth check —
        // Kelompok Pattern's jumlah_proses (2) is what actually governs here.
        $assignment = LotMakingAssignment::create(['part_id' => $part->id, 'machine_id' => $machine->id, 'proses' => 5]);

        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);

        $this->actingAs($this->authorizedUser())
            ->put(route('lot-making-plannings.assign', $planning), [
                'lot_making_assignment_id' => $assignment->id,
                'shift' => 1,
                'window' => $this->anyFreeWindowValue($board, $machine->id, 1),
            ])
            ->assertSessionHasErrors('lot_making_assignment_id');

        $this->assertSame(0, Pattern::count());
    }

    public function test_assigning_is_rejected_when_proses_exceeds_jumlah_proses_without_kelompok_pattern(): void
    {
        $board = $this->makeTodaysBoard();
        $machine = Machine::create(['name' => 'PT91']);
        $part = Part::create(['part_no' => 'P1']);

        // No PatternGroupItem — the Lot Making record's own jumlah_proses (3)
        // is what governs here instead.
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 10, 'slot' => 1, 'loading_time' => 30, 'jumlah_proses' => 3]);
        $assignment = LotMakingAssignment::create(['part_id' => $part->id, 'machine_id' => $machine->id, 'proses' => 5]);

        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);

        $this->actingAs($this->authorizedUser())
            ->put(route('lot-making-plannings.assign', $planning), [
                'lot_making_assignment_id' => $assignment->id,
                'shift' => 1,
                'window' => $this->anyFreeWindowValue($board, $machine->id, 1),
            ])
            ->assertSessionHasErrors('lot_making_assignment_id');

        $this->assertSame(0, Pattern::count());
    }

    public function test_finishing_deletes_the_pattern_row_so_the_andon_slot_reverts_to_free_time(): void
    {
        $board = PatternBoard::create(['name' => 'A']);
        $machine = Machine::create(['name' => 'PT91']);
        $part = Part::create(['part_no' => 'BZ020-KK010']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id, 'part_id' => $part->id, 'shift' => 1,
            'urutan' => 1, 'lot' => 10, 'loading_time' => 30, 'jumlah_proses' => 3,
            'total_kanban' => 1, 'dandori' => 0,
        ]);
        $pattern = Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $machine->id, 'part_id' => $part->id, 'shift' => 1, 'proses' => 2]);

        $planning = LotMakingPlanning::create([
            'part_id' => $part->id, 'lot' => 10,
            'pattern_board_id' => $board->id, 'machine_id' => $machine->id, 'shift' => 1, 'proses' => 2,
            'pattern_id' => $pattern->id,
        ]);

        $this->actingAs($this->authorizedUser())
            ->post(route('lot-making-plannings.finish', $planning))
            ->assertRedirect(route('lot-making-plannings.index'));

        $planning->refresh();
        $this->assertTrue($planning->isFinished());
        $this->assertNull($planning->pattern_id);
        $this->assertSame(0, Pattern::count());

        $html = $this->get("/andon/{$board->id}")->getContent();
        $this->assertStringNotContainsString('BZ020-KK010', $html);
        $this->assertStringContainsString('FREE TIME', $html);
    }

    public function test_cancelling_an_in_progress_item_deletes_the_pattern_and_reverts_it_to_open(): void
    {
        $board = PatternBoard::create(['name' => 'A']);
        $machine = Machine::create(['name' => 'PT91']);
        $part = Part::create(['part_no' => 'BZ020-KK010']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id, 'part_id' => $part->id, 'shift' => 1,
            'urutan' => 1, 'lot' => 10, 'loading_time' => 30, 'jumlah_proses' => 3,
            'total_kanban' => 1, 'dandori' => 0,
        ]);
        $pattern = Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $machine->id, 'part_id' => $part->id, 'shift' => 1, 'proses' => 2]);

        $planning = LotMakingPlanning::create([
            'part_id' => $part->id, 'lot' => 10,
            'pattern_board_id' => $board->id, 'machine_id' => $machine->id, 'shift' => 1, 'proses' => 2,
            'pattern_id' => $pattern->id,
        ]);

        $this->actingAs($this->authorizedUser())
            ->post(route('lot-making-plannings.cancel', $planning))
            ->assertRedirect(route('lot-making-plannings.index'));

        $planning->refresh();
        $this->assertFalse($planning->isAssigned());
        $this->assertFalse($planning->isFinished());
        $this->assertSame('open', $planning->status);
        $this->assertNull($planning->pattern_id);
        $this->assertNull($planning->machine_id);
        $this->assertNull($planning->pattern_board_id);
        $this->assertNull($planning->shift);
        $this->assertNull($planning->proses);
        $this->assertSame(0, Pattern::count());

        // The Andon slot reverts to FREE TIME, same as Finish.
        $html = $this->get("/andon/{$board->id}")->getContent();
        $this->assertStringNotContainsString('BZ020-KK010', $html);
        $this->assertStringContainsString('FREE TIME', $html);

        // And the item shows up back on the Open tab, ready to be reassigned.
        $openHtml = $this->actingAs($this->authorizedUser())->get(route('lot-making-plannings.index'))->getContent();
        $this->assertStringContainsString('BZ020-KK010', $openHtml);
    }

    public function test_cancelling_is_rejected_when_the_item_is_not_assigned_or_already_finished(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        $user = $this->authorizedUser();

        $open = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);
        $this->actingAs($user)->post(route('lot-making-plannings.cancel', $open));
        $this->assertSame('open', $open->fresh()->status);

        $closed = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10, 'finished_at' => now()]);
        $this->actingAs($user)->post(route('lot-making-plannings.cancel', $closed));
        $this->assertTrue($closed->fresh()->isFinished());
    }

    public function test_the_planning_menu_item_appears_in_the_sidebar(): void
    {
        $this->actingAs($this->authorizedUser())
            ->get(route('lot-makings.index'))
            ->assertOk()
            ->assertSee('Planning')
            ->assertSee(route('lot-making-plannings.index'), false);
    }
}
