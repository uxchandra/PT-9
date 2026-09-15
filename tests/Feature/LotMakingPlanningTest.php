<?php

namespace Tests\Feature;

use App\Models\CalendarEntry;
use App\Models\LotMaking;
use App\Models\LotMakingAssignment;
use App\Models\LotMakingCycle;
use App\Models\LotMakingPlanning;
use App\Models\LotMakingPlanningAssignment;
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
     * on $board, exactly as the Assign form's own JS would supply it.
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

    public function test_index_separates_open_in_progress_and_close_by_individual_proses_step(): void
    {
        $partOpen = Part::create(['part_no' => 'OPEN-1']);
        $partProgress = Part::create(['part_no' => 'PROGRESS-1']);
        $partClosed = Part::create(['part_no' => 'CLOSED-1']);
        LotMaking::create(['part_id' => $partOpen->id, 'jumlah_proses' => 1]);
        LotMaking::create(['part_id' => $partProgress->id, 'jumlah_proses' => 1]);
        LotMaking::create(['part_id' => $partClosed->id, 'jumlah_proses' => 1]);

        $board = PatternBoard::create(['name' => 'A']);
        $machine = Machine::create(['name' => 'PT91']);
        $pattern = Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $machine->id, 'part_id' => $partProgress->id, 'shift' => 1, 'proses' => 1]);

        LotMakingPlanning::create(['part_id' => $partOpen->id, 'lot' => 10]);
        $inProgress = LotMakingPlanning::create(['part_id' => $partProgress->id, 'lot' => 10]);
        $inProgress->assignments()->create(['proses' => 1, 'machine_id' => $machine->id, 'shift' => 1, 'pattern_id' => $pattern->id]);
        $closed = LotMakingPlanning::create(['part_id' => $partClosed->id, 'lot' => 10]);
        $closed->assignments()->create(['proses' => 1, 'machine_id' => $machine->id, 'shift' => 1, 'pattern_id' => null, 'finished_at' => now()]);

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
    }

    public function test_a_lot_splits_independently_so_each_proses_step_lands_in_its_own_tab(): void
    {
        $board = $this->makeTodaysBoard();
        $machine = Machine::create(['name' => 'PT91']);
        $part = Part::create(['part_no' => 'MIX-1']);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 10, 'slot' => 1, 'loading_time' => 30, 'jumlah_proses' => 2]);

        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);
        $user = $this->authorizedUser();

        // Only proses 1 assigned — proses 2 stays pending on the very same
        // lot, yet the two must land on two different tabs.
        $this->actingAs($user)->put(route('lot-making-plannings.assign', $planning), [
            'proses' => 1, 'machine_id' => $machine->id, 'shift' => 1,
            'window' => $this->anyFreeWindowValue($board, $machine->id, 1),
        ]);

        $openHtml = $this->actingAs($user)->get(route('lot-making-plannings.index'))->getContent();
        $this->assertStringContainsString('MIX-1', $openHtml);
        $this->assertStringContainsString('2/2', $openHtml);
        $this->assertStringNotContainsString('1/2', $openHtml);

        $progressHtml = $this->actingAs($user)->get(route('lot-making-plannings.index', ['status' => 'in_progress']))->getContent();
        $this->assertStringContainsString('MIX-1', $progressHtml);
        $this->assertStringContainsString('1/2', $progressHtml);
        $this->assertStringNotContainsString('2/2', $progressHtml);
        $this->assertStringContainsString('PT91', $progressHtml);
    }

    public function test_assigning_a_proses_step_creates_a_real_pattern_row_that_shows_up_on_andon(): void
    {
        $board = $this->makeTodaysBoard();
        $machine = Machine::create(['name' => 'PT91']);
        $part = Part::create(['part_no' => 'BZ020-KK010']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id, 'part_id' => $part->id, 'shift' => 1,
            'urutan' => 1, 'lot' => 10, 'loading_time' => 30, 'jumlah_proses' => 3,
            'total_kanban' => 1, 'dandori' => 0,
        ]);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 10, 'slot' => 1, 'jumlah_proses' => 3]);
        LotMakingAssignment::create(['part_id' => $part->id, 'machine_id' => $machine->id, 'proses' => 2]);

        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);

        $this->actingAs($this->authorizedUser())
            ->put(route('lot-making-plannings.assign', $planning), [
                'proses' => 2,
                'machine_id' => $machine->id,
                'shift' => 1,
                'window' => $this->anyFreeWindowValue($board, $machine->id, 1),
            ])
            ->assertRedirect(route('lot-making-plannings.index'));

        $planning->refresh();
        $this->assertTrue($planning->isAssigned());

        $assignment = $planning->assignments()->first();
        $this->assertSame(2, $assignment->proses);
        $this->assertSame($machine->id, $assignment->machine_id);
        $this->assertSame(1, $assignment->shift);
        $this->assertNotNull($assignment->pattern_id);

        $pattern = Pattern::find($assignment->pattern_id);
        $this->assertSame($part->id, $pattern->part_id);
        $this->assertSame($machine->id, $pattern->machine_id);
        $this->assertSame(2, $pattern->proses);

        // Same label composition Assignment Mesin already uses — no extra
        // Andon rendering code needed for this to show up correctly.
        $html = $this->get("/andon/{$board->id}")->getContent();
        $this->assertStringContainsString('BZ020-KK010 2/3', $html);
    }

    public function test_assigning_is_rejected_when_the_chosen_window_is_no_longer_free(): void
    {
        $board = $this->makeTodaysBoard();
        $machine = Machine::create(['name' => 'PT91']);
        $part = Part::create(['part_no' => 'P1']);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 10, 'slot' => 1, 'loading_time' => 30, 'jumlah_proses' => 1]);

        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);

        $this->actingAs($this->authorizedUser())
            ->put(route('lot-making-plannings.assign', $planning), [
                'proses' => 1, 'machine_id' => $machine->id, 'shift' => 1,
                // A window that was never real for this machine/shift.
                'window' => '9999:10999',
            ])
            ->assertSessionHasErrors('window');

        $this->assertSame(0, Pattern::count());
    }

    public function test_each_proses_step_has_its_own_shift_independent_of_its_siblings(): void
    {
        $board = $this->makeTodaysBoard();
        $machineA = Machine::create(['name' => 'PT91']);
        $machineB = Machine::create(['name' => 'PT92']);
        $part = Part::create(['part_no' => 'P1']);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 10, 'slot' => 1, 'loading_time' => 30, 'jumlah_proses' => 2]);

        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);
        $user = $this->authorizedUser();

        $this->actingAs($user)->put(route('lot-making-plannings.assign', $planning), [
            'proses' => 1, 'machine_id' => $machineA->id, 'shift' => 2,
            'window' => $this->anyFreeWindowValue($board, $machineA->id, 2),
        ]);

        // Second step picks a genuinely different shift — nothing locks it
        // to whatever the first step used.
        $this->actingAs($user)->put(route('lot-making-plannings.assign', $planning), [
            'proses' => 2, 'machine_id' => $machineB->id, 'shift' => 1,
            'window' => $this->anyFreeWindowValue($board, $machineB->id, 1),
        ]);

        $byProses = $planning->assignments()->get()->keyBy('proses');
        $this->assertSame(2, $byProses->get(1)->shift);
        $this->assertSame(1, $byProses->get(2)->shift);

        $patternShifts = Pattern::where('part_id', $part->id)->orderBy('proses')->pluck('shift')->all();
        $this->assertSame([2, 1], $patternShifts);
    }

    public function test_a_lot_can_have_more_than_one_proses_step_assigned_to_different_machines(): void
    {
        $board = $this->makeTodaysBoard();
        $machineA = Machine::create(['name' => 'PT91']);
        $machineB = Machine::create(['name' => 'PT92']);
        $part = Part::create(['part_no' => 'MULTI-1']);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 10, 'slot' => 1, 'loading_time' => 30, 'jumlah_proses' => 2]);

        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);
        $user = $this->authorizedUser();

        $this->actingAs($user)->put(route('lot-making-plannings.assign', $planning), [
            'proses' => 1, 'machine_id' => $machineA->id, 'shift' => 1,
            'window' => $this->anyFreeWindowValue($board, $machineA->id, 1),
        ]);
        $this->actingAs($user)->put(route('lot-making-plannings.assign', $planning), [
            'proses' => 2, 'machine_id' => $machineB->id, 'shift' => 1,
            'window' => $this->anyFreeWindowValue($board, $machineB->id, 1),
        ]);

        $this->assertSame(2, Pattern::where('part_id', $part->id)->count());

        $html = $this->get("/andon/{$board->id}")->getContent();
        $this->assertStringContainsString('MULTI-1 1/2', $html);
        $this->assertStringContainsString('MULTI-1 2/2', $html);
    }

    public function test_assigning_the_same_proses_step_twice_is_rejected(): void
    {
        $board = $this->makeTodaysBoard();
        $machine = Machine::create(['name' => 'PT91']);
        $part = Part::create(['part_no' => 'P1']);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 10, 'slot' => 1, 'loading_time' => 30, 'jumlah_proses' => 2]);

        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);
        $user = $this->authorizedUser();

        $this->actingAs($user)->put(route('lot-making-plannings.assign', $planning), [
            'proses' => 1, 'machine_id' => $machine->id, 'shift' => 1,
            'window' => $this->anyFreeWindowValue($board, $machine->id, 1),
        ]);
        $this->actingAs($user)->put(route('lot-making-plannings.assign', $planning), [
            'proses' => 1, 'machine_id' => $machine->id, 'shift' => 1,
            'window' => $this->anyFreeWindowValue($board, $machine->id, 1),
        ]);

        $this->assertSame(1, $planning->assignments()->count());
        $this->assertSame(1, Pattern::where('part_id', $part->id)->count());
    }

    public function test_the_open_tab_renders_a_fallback_row_when_jumlah_proses_is_not_configured(): void
    {
        $part = Part::create(['part_no' => 'NO-JP-1']);
        // No LotMaking record at all — jumlah_proses is unknown, so there's
        // no proses count to loop over for this row.
        LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);

        $html = $this->actingAs($this->authorizedUser())->get(route('lot-making-plannings.index'))->getContent();

        $this->assertStringContainsString('NO-JP-1', $html);
        $this->assertStringContainsString('Jumlah Proses part ini belum diisi.', $html);
    }

    public function test_assigning_is_rejected_when_the_lot_has_no_jumlah_proses_configured(): void
    {
        $this->makeTodaysBoard();
        $machine = Machine::create(['name' => 'PT91']);
        $part = Part::create(['part_no' => 'P1']);
        // No LotMaking record at all — jumlah_proses is unknown.
        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);

        $this->actingAs($this->authorizedUser())
            ->put(route('lot-making-plannings.assign', $planning), [
                'proses' => 1, 'machine_id' => $machine->id, 'shift' => 1,
            ])
            ->assertSessionHasErrors('machine_id');

        $this->assertFalse($planning->fresh()->isAssigned());
        $this->assertSame(0, Pattern::count());
    }

    public function test_assigning_is_rejected_when_proses_exceeds_jumlah_proses(): void
    {
        $board = $this->makeTodaysBoard();
        $machine = Machine::create(['name' => 'PT91']);
        $part = Part::create(['part_no' => 'P1']);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 10, 'slot' => 1, 'loading_time' => 30, 'jumlah_proses' => 2]);

        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);

        $this->actingAs($this->authorizedUser())
            ->put(route('lot-making-plannings.assign', $planning), [
                'proses' => 5, 'machine_id' => $machine->id, 'shift' => 1,
                'window' => $this->anyFreeWindowValue($board, $machine->id, 1),
            ])
            ->assertSessionHasErrors('proses');

        $this->assertSame(0, Pattern::count());
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
        // set on its Lot Making record either — only jumlah_proses, so the
        // request gets past validation and hits the loading_time check.
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 10, 'slot' => 1, 'jumlah_proses' => 1]);

        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);

        $this->actingAs($this->authorizedUser())
            ->put(route('lot-making-plannings.assign', $planning), [
                'proses' => 1, 'machine_id' => $machine->id, 'shift' => 1,
                'window' => $this->anyFreeWindowValue($board, $machine->id, 1),
            ])
            ->assertSessionHasErrors('machine_id');

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

        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 20]);

        $this->actingAs($this->authorizedUser())
            ->put(route('lot-making-plannings.assign', $planning), [
                'proses' => 2, 'machine_id' => $machine->id, 'shift' => 1,
                'window' => $this->anyFreeWindowValue($board, $machine->id, 1),
            ])
            ->assertRedirect(route('lot-making-plannings.index'));

        $planning->refresh();
        $this->assertTrue($planning->isAssigned());

        $pattern = Pattern::find($planning->assignments()->first()->pattern_id);
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

        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);

        $this->actingAs($this->authorizedUser())
            ->put(route('lot-making-plannings.assign', $planning), [
                'proses' => 1, 'machine_id' => $machine->id, 'shift' => 1,
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

    public function test_closing_a_proses_step_deletes_its_pattern_row_so_the_andon_slot_reverts_to_free_time(): void
    {
        $board = PatternBoard::create(['name' => 'A']);
        $machine = Machine::create(['name' => 'PT91']);
        $part = Part::create(['part_no' => 'BZ020-KK010']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id, 'part_id' => $part->id, 'shift' => 1,
            'urutan' => 1, 'lot' => 10, 'loading_time' => 30, 'jumlah_proses' => 3,
            'total_kanban' => 1, 'dandori' => 0,
        ]);
        $pattern = Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $machine->id, 'part_id' => $part->id, 'shift' => 1, 'proses' => 1]);

        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);
        $assignment = $planning->assignments()->create(['proses' => 1, 'machine_id' => $machine->id, 'shift' => 1, 'pattern_id' => $pattern->id]);

        $this->actingAs($this->authorizedUser())
            ->post(route('lot-making-plannings.close', $assignment))
            ->assertRedirect(route('lot-making-plannings.index'));

        $assignment->refresh();
        $this->assertTrue($assignment->isFinished());
        $this->assertNull($assignment->pattern_id);
        $this->assertSame(0, Pattern::count());

        $html = $this->get("/andon/{$board->id}")->getContent();
        $this->assertStringNotContainsString('BZ020-KK010', $html);
        $this->assertStringContainsString('FREE TIME', $html);
    }

    public function test_closing_one_proses_step_does_not_affect_its_siblings(): void
    {
        $board = PatternBoard::create(['name' => 'A']);
        $machineA = Machine::create(['name' => 'PT91']);
        $machineB = Machine::create(['name' => 'PT92']);
        $part = Part::create(['part_no' => 'BZ020-KK010']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id, 'part_id' => $part->id, 'shift' => 1,
            'urutan' => 1, 'lot' => 10, 'loading_time' => 30, 'jumlah_proses' => 3,
            'total_kanban' => 1, 'dandori' => 0,
        ]);
        $patternA = Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $machineA->id, 'part_id' => $part->id, 'shift' => 1, 'proses' => 1]);
        $patternB = Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $machineB->id, 'part_id' => $part->id, 'shift' => 1, 'proses' => 2]);

        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);
        $assignmentA = $planning->assignments()->create(['proses' => 1, 'machine_id' => $machineA->id, 'shift' => 1, 'pattern_id' => $patternA->id]);
        $assignmentB = $planning->assignments()->create(['proses' => 2, 'machine_id' => $machineB->id, 'shift' => 1, 'pattern_id' => $patternB->id]);

        $this->actingAs($this->authorizedUser())->post(route('lot-making-plannings.close', $assignmentA));

        $this->assertNull(Pattern::find($patternA->id));
        $this->assertNotNull(Pattern::find($patternB->id));
        $this->assertFalse($assignmentB->fresh()->isFinished());

        $html = $this->get("/andon/{$board->id}")->getContent();
        $this->assertStringNotContainsString('BZ020-KK010 1/3', $html);
        $this->assertStringContainsString('BZ020-KK010 2/3', $html);
    }

    public function test_closing_is_rejected_once_already_closed(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);
        $assignment = $planning->assignments()->create(['proses' => 1, 'machine_id' => Machine::create(['name' => 'PT91'])->id, 'shift' => 1, 'finished_at' => now()]);

        $this->actingAs($this->authorizedUser())
            ->post(route('lot-making-plannings.close', $assignment))
            ->assertRedirect();

        $this->assertNotNull($assignment->fresh()->finished_at);
    }

    public function test_cancelling_one_proses_step_deletes_only_its_own_pattern_row(): void
    {
        $board = PatternBoard::create(['name' => 'A']);
        $machineA = Machine::create(['name' => 'PT91']);
        $machineB = Machine::create(['name' => 'PT92']);
        $part = Part::create(['part_no' => 'BZ020-KK010']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id, 'part_id' => $part->id, 'shift' => 1,
            'urutan' => 1, 'lot' => 10, 'loading_time' => 30, 'jumlah_proses' => 3,
            'total_kanban' => 1, 'dandori' => 0,
        ]);
        $patternA = Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $machineA->id, 'part_id' => $part->id, 'shift' => 1, 'proses' => 1]);
        $patternB = Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $machineB->id, 'part_id' => $part->id, 'shift' => 1, 'proses' => 2]);

        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);
        $assignmentA = $planning->assignments()->create(['proses' => 1, 'machine_id' => $machineA->id, 'shift' => 1, 'pattern_id' => $patternA->id]);
        $planning->assignments()->create(['proses' => 2, 'machine_id' => $machineB->id, 'shift' => 1, 'pattern_id' => $patternB->id]);

        $this->actingAs($this->authorizedUser())
            ->post(route('lot-making-plannings.cancel', $assignmentA))
            ->assertRedirect(route('lot-making-plannings.index'));

        $this->assertNull(Pattern::find($patternA->id));
        $this->assertNotNull(Pattern::find($patternB->id));

        // Step 1 is gone outright (no history kept for a cancel), step 2
        // stays assigned.
        $this->assertSame(1, $planning->assignments()->count());

        $html = $this->get("/andon/{$board->id}")->getContent();
        $this->assertStringNotContainsString('BZ020-KK010 1/3', $html);
        $this->assertStringContainsString('BZ020-KK010 2/3', $html);
    }

    public function test_cancelling_is_rejected_once_the_step_is_closed(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        $machine = Machine::create(['name' => 'PT91']);
        $board = PatternBoard::create(['name' => 'A']);
        $pattern = Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $machine->id, 'part_id' => $part->id, 'shift' => 1, 'proses' => 1, 'loading_time' => 30, 'jumlah_proses' => 1, 'dandori' => 0, 'total_kanban' => 1]);

        $planning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10]);
        // Deliberately still carrying a pattern_id despite being finished —
        // an invariant close() itself never produces, but the guard here
        // must hold regardless of how a step ended up finished.
        $assignment = $planning->assignments()->create(['proses' => 1, 'machine_id' => $machine->id, 'shift' => 1, 'pattern_id' => $pattern->id, 'finished_at' => now()]);

        $this->actingAs($this->authorizedUser())->post(route('lot-making-plannings.cancel', $assignment));

        $this->assertNotNull(Pattern::find($pattern->id));
        $this->assertNotNull(LotMakingPlanningAssignment::find($assignment->id));
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
