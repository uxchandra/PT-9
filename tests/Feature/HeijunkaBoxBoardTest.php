<?php

namespace Tests\Feature;

use App\Models\HeijunkaBoxCycleGroup;
use App\Models\HeijunkaBoxSchedule;
use App\Models\KeseiPart;
use App\Models\KeseiScan;
use App\Models\LotMaking;
use App\Models\Part;
use App\Models\StockSnapshot;
use App\Models\User;
use App\Services\HeijunkaBoxBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HeijunkaBoxBoardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every test's rows live inside groups now (see
     * HeijunkaBoxBoard::data()'s grouped-by-cycle_issue shape) — this
     * flattens them back out for assertions that don't care about grouping.
     */
    private function flatRows(array $data)
    {
        return collect($data['groups'])->flatMap(fn (array $g) => $g['rows'])->values();
    }

    public function test_the_board_is_public_and_shows_the_title(): void
    {
        $this->get(route('andon-heijunka-box.show'))
            ->assertOk()
            ->assertSee('HEIJUNKA BOX LINE 9');
    }

    public function test_a_tick_fires_at_the_fixed_slot_time_not_the_raw_decrease_time(): void
    {
        Carbon::setTestNow('2026-09-15 07:15:00');
        $part = Part::create(['part_no' => 'HB-1', 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);
        HeijunkaBoxSchedule::create(['part_id' => $part->id, 'cycle_issue' => '1-2-X', 'slots' => ['07:10', '08:10']]);

        StockSnapshot::create(['part_no' => 'HB-1', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 06:30')]);
        // Decrease arrives at 07:05 — BEFORE the 07:10 slot.
        StockSnapshot::create(['part_no' => 'HB-1', 'stock' => 99, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 07:05')]);

        $data = app(HeijunkaBoxBoard::class)->data();
        $row = $this->flatRows($data)->firstWhere('label', 'HB-1');

        $this->assertSame(1, count($row['ticks']));
        $this->assertSame('07:10', $row['ticks'][0]['time']);

        Carbon::setTestNow();
    }

    public function test_backlog_spreads_across_consecutive_future_slots_instead_of_bursting_on_one(): void
    {
        Carbon::setTestNow('2026-09-15 09:15:00');
        $part = Part::create(['part_no' => 'HB-2', 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);
        HeijunkaBoxSchedule::create(['part_id' => $part->id, 'cycle_issue' => '1-2-X', 'slots' => ['07:10', '08:10', '09:10']]);

        StockSnapshot::create(['part_no' => 'HB-2', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 06:00')]);
        // -3 kanban, all at once, before the first slot.
        StockSnapshot::create(['part_no' => 'HB-2', 'stock' => 97, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 07:05')]);

        $data = app(HeijunkaBoxBoard::class)->data();
        $row = $this->flatRows($data)->firstWhere('label', 'HB-2');

        $times = collect($row['ticks'])->pluck('time')->sort()->values()->all();
        $this->assertSame(['07:10', '08:10', '09:10'], $times);

        Carbon::setTestNow();
    }

    public function test_a_slot_with_no_backlog_at_its_own_time_never_fires_even_once_backlog_arrives_later(): void
    {
        // The core "release ahead of the progress bar, never behind it"
        // rule: a slot only fires with whatever backlog exists AT ITS OWN
        // moment — it doesn't wait around for backlog that shows up after.
        Carbon::setTestNow('2026-09-15 07:15:00');
        $part = Part::create(['part_no' => 'HB-3', 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);
        HeijunkaBoxSchedule::create(['part_id' => $part->id, 'cycle_issue' => '1-2-X', 'slots' => ['07:10', '08:10']]);

        StockSnapshot::create(['part_no' => 'HB-3', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 06:30')]);

        // Right after 07:10, with no decrease yet — nothing fires.
        $data = app(HeijunkaBoxBoard::class)->data();
        $row = $this->flatRows($data)->firstWhere('label', 'HB-3');
        $this->assertSame(0, count($row['ticks']));

        // Now a decrease lands at 07:30 — after the 07:10 slot already passed.
        StockSnapshot::create(['part_no' => 'HB-3', 'stock' => 99, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 07:30')]);

        Carbon::setTestNow('2026-09-15 08:15:00');
        $data = app(HeijunkaBoxBoard::class)->data();
        $row = $this->flatRows($data)->firstWhere('label', 'HB-3');

        // Fires at 08:10 — the next slot ahead of when the backlog showed
        // up — NOT retroactively at the already-passed 07:10.
        $this->assertSame(1, count($row['ticks']));
        $this->assertSame('08:10', $row['ticks'][0]['time']);

        Carbon::setTestNow();
    }

    public function test_tick_colour_states_match_the_same_rules_as_the_lt_kbn_heijunka_board(): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        $part = Part::create(['part_no' => 'HB-4', 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);
        HeijunkaBoxSchedule::create(['part_id' => $part->id, 'cycle_issue' => '1-2-X', 'slots' => ['07:10', '10:20', '11:50']]);

        StockSnapshot::create(['part_no' => 'HB-4', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 06:00')]);
        // -3 kanban well before any slot — all 3 slots fire in order.
        StockSnapshot::create(['part_no' => 'HB-4', 'stock' => 97, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 07:05')]);

        // Scan one of them (FIFO — the earliest fired, 07:10) after it fired.
        KeseiScan::create(['part_no' => 'HB-4', 'location' => 'finish-goods', 'raw' => 'HB-4', 'scanned_by' => User::factory()->create()->id, 'scanned_at' => Carbon::parse('2026-09-15 07:20')]);

        $data = app(HeijunkaBoxBoard::class)->data();
        $row = $this->flatRows($data)->firstWhere('label', 'HB-4');
        $byTime = collect($row['ticks'])->keyBy('time');

        $this->assertSame('scanned', $byTime['07:10']['heijunka_status']);
        $this->assertSame('overdue', $byTime['10:20']['heijunka_status']); // fired 1h40m ago, unscanned
        $this->assertSame('pending', $byTime['11:50']['heijunka_status']); // fired only 10 min ago — still in grace

        Carbon::setTestNow();
    }

    public function test_a_recently_fired_unscanned_tick_is_pending_not_overdue(): void
    {
        Carbon::setTestNow('2026-09-15 07:20:00');
        $part = Part::create(['part_no' => 'HB-5', 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);
        HeijunkaBoxSchedule::create(['part_id' => $part->id, 'cycle_issue' => '1-2-X', 'slots' => ['07:10']]);

        StockSnapshot::create(['part_no' => 'HB-5', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 06:00')]);
        StockSnapshot::create(['part_no' => 'HB-5', 'stock' => 99, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 07:05')]);

        $data = app(HeijunkaBoxBoard::class)->data();
        $row = $this->flatRows($data)->firstWhere('label', 'HB-5');

        $this->assertSame('pending', $row['ticks'][0]['heijunka_status']);

        Carbon::setTestNow();
    }

    public function test_a_part_not_registered_in_kesei_or_lot_making_is_left_off_the_board(): void
    {
        $part = Part::create(['part_no' => 'HB-ORPHAN']);
        HeijunkaBoxSchedule::create(['part_id' => $part->id, 'cycle_issue' => '1-2-X', 'slots' => ['07:10']]);

        $data = app(HeijunkaBoxBoard::class)->data();

        $this->assertNull($this->flatRows($data)->firstWhere('label', 'HB-ORPHAN'));
    }

    public function test_a_lot_making_part_works_the_same_way_as_a_kesei_one(): void
    {
        Carbon::setTestNow('2026-09-15 09:00:00');
        $part = Part::create(['part_no' => 'HB-LM', 'qty_kbn' => 1]);
        LotMaking::create(['part_id' => $part->id, 'level' => 'finish-goods']);
        HeijunkaBoxSchedule::create(['part_id' => $part->id, 'cycle_issue' => '1-4-X', 'slots' => ['07:10']]);

        StockSnapshot::create(['part_no' => 'HB-LM', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 06:00')]);
        StockSnapshot::create(['part_no' => 'HB-LM', 'stock' => 99, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 07:05')]);

        $data = app(HeijunkaBoxBoard::class)->data();
        $row = $this->flatRows($data)->firstWhere('label', 'HB-LM');

        $this->assertNotNull($row);
        $this->assertSame('lot-making', $row['source']);
        $this->assertSame(1, count($row['ticks']));

        Carbon::setTestNow();
    }

    public function test_parts_are_grouped_by_cycle_issue_and_keep_the_sheets_own_row_order(): void
    {
        $partA = Part::create(['part_no' => 'HB-G1']);
        $partB = Part::create(['part_no' => 'HB-G2']);
        $partC = Part::create(['part_no' => 'HB-G3']);
        KeseiPart::create(['part_id' => $partA->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);
        KeseiPart::create(['part_id' => $partB->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);
        KeseiPart::create(['part_id' => $partC->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);

        // Fictitious group names — the real "1-2-X"/"1-4-X" groups are
        // already seeded from the real sheet import, so reusing those
        // labels here would collide with (and get merged into) real data.
        // Deliberately out of alphabetical order — sort_order is what
        // should win, not the label, matching how the sheet lists its rows.
        HeijunkaBoxSchedule::create(['part_id' => $partB->id, 'cycle_issue' => 'TEST-2-X', 'sort_order' => 1, 'slots' => []]);
        HeijunkaBoxSchedule::create(['part_id' => $partA->id, 'cycle_issue' => 'TEST-2-X', 'sort_order' => 0, 'slots' => []]);
        HeijunkaBoxSchedule::create(['part_id' => $partC->id, 'cycle_issue' => 'TEST-4-X', 'sort_order' => 0, 'slots' => []]);

        HeijunkaBoxCycleGroup::create(['cycle_issue' => 'TEST-2-X', 'sort_order' => 5, 'random_numbers' => []]);
        HeijunkaBoxCycleGroup::create(['cycle_issue' => 'TEST-4-X', 'sort_order' => 1, 'random_numbers' => []]);

        $data = app(HeijunkaBoxBoard::class)->data();
        $groups = collect($data['groups']);

        // TEST-4-X's group sort_order (1) is lower than TEST-2-X's (5), so
        // it comes first, even though "TEST-2-X" sorts first alphabetically.
        $this->assertSame(
            ['TEST-4-X', 'TEST-2-X'],
            $groups->whereIn('cycle_issue', ['TEST-2-X', 'TEST-4-X'])->pluck('cycle_issue')->all()
        );

        $group2x = $groups->firstWhere('cycle_issue', 'TEST-2-X');
        $this->assertSame(['HB-G1', 'HB-G2'], collect($group2x['rows'])->pluck('label')->all());
    }

    public function test_the_random_number_row_comes_from_the_groups_own_fixed_sheet_data(): void
    {
        $part = Part::create(['part_no' => 'HB-RN']);
        KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);
        HeijunkaBoxSchedule::create(['part_id' => $part->id, 'cycle_issue' => 'TEST-2-X', 'slots' => []]);
        HeijunkaBoxCycleGroup::create([
            'cycle_issue' => 'TEST-2-X',
            'sort_order' => 0,
            'random_numbers' => ['07:10' => 1, '07:40' => 9],
        ]);

        $data = app(HeijunkaBoxBoard::class)->data();
        $group = collect($data['groups'])->firstWhere('cycle_issue', 'TEST-2-X');

        $this->assertSame(['07:10' => 1, '07:40' => 9], $group['random_numbers']);
    }

    public function test_the_subtotal_row_counts_ticks_actually_showing_on_the_board_not_the_static_plan(): void
    {
        Carbon::setTestNow('2026-09-15 09:15:00');
        $partA = Part::create(['part_no' => 'HB-SUB-A', 'qty_kbn' => 1]);
        $partB = Part::create(['part_no' => 'HB-SUB-B', 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $partA->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);
        KeseiPart::create(['part_id' => $partB->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);

        // A has 3 planned slots but only backlog to actually fire 2 of them
        // by "now" — the subtotal must reflect the 2 fired ticks, not the 3
        // planned ones.
        HeijunkaBoxSchedule::create(['part_id' => $partA->id, 'cycle_issue' => 'TEST-2-X', 'slots' => ['07:10', '08:10', '09:10']]);
        HeijunkaBoxSchedule::create(['part_id' => $partB->id, 'cycle_issue' => 'TEST-2-X', 'slots' => ['07:10']]);

        StockSnapshot::create(['part_no' => 'HB-SUB-A', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 06:00')]);
        StockSnapshot::create(['part_no' => 'HB-SUB-A', 'stock' => 98, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 07:05')]);
        StockSnapshot::create(['part_no' => 'HB-SUB-B', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 06:00')]);
        StockSnapshot::create(['part_no' => 'HB-SUB-B', 'stock' => 99, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 07:05')]);

        $data = app(HeijunkaBoxBoard::class)->data();
        $group = collect($data['groups'])->firstWhere('cycle_issue', 'TEST-2-X');

        $this->assertSame(2, $group['subtotals']['07:10']); // A + B both fired here
        $this->assertSame(1, $group['subtotals']['08:10']); // A's second tick
        $this->assertSame(0, $group['subtotals']['09:10']); // not reached yet — never fired

        Carbon::setTestNow();
    }
}
