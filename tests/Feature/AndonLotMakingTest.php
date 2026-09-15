<?php

namespace Tests\Feature;

use App\Models\LotMaking;
use App\Models\LotMakingAssignment;
use App\Models\LotMakingCycle;
use App\Models\LotMakingPlanning;
use App\Models\Machine;
use App\Models\Part;
use App\Models\Pattern;
use App\Models\PatternBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AndonLotMakingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_board_is_public(): void
    {
        $this->get(route('andon-lot-making.show'))
            ->assertOk()
            ->assertSee('LOT MAKING BY SCAN LINE 9');
    }

    public function test_the_planning_roller_shows_the_machine_for_an_in_progress_cycle(): void
    {
        // Status itself is no longer printed per-row — which section (Open
        // vs In Progress) a cycle lands in already says that.
        $part = Part::create(['part_no' => 'P1']);
        $board = PatternBoard::create(['name' => 'A']);
        $machine = Machine::create(['name' => 'PT91']);
        $pattern = Pattern::create([
            'pattern_board_id' => $board->id, 'machine_id' => $machine->id, 'part_id' => $part->id,
            'shift' => 1, 'proses' => 1, 'loading_time' => 30, 'jumlah_proses' => 1, 'dandori' => 0, 'total_kanban' => 1,
        ]);
        $cycle = LotMakingCycle::create([
            'part_no' => 'P1', 'lot_produksi' => 50, 'source' => 'scan', 'completed_at' => now(),
        ]);
        $planning = LotMakingPlanning::create([
            'part_id' => $part->id, 'lot' => 50, 'lot_making_cycle_id' => $cycle->id,
        ]);
        $planning->assignments()->create(['proses' => 1, 'machine_id' => $machine->id, 'shift' => 1, 'pattern_id' => $pattern->id]);

        $html = $this->get(route('andon-lot-making.show'))->getContent();

        $this->assertStringContainsString('PT91', $html);
    }

    public function test_the_planning_roller_lists_every_registered_machine_for_an_open_cycle(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 50, 'slot' => 1]);
        $machineA = Machine::create(['name' => 'PT91']);
        $machineB = Machine::create(['name' => 'PT92']);
        LotMakingAssignment::create(['part_id' => $part->id, 'machine_id' => $machineA->id, 'proses' => 1]);
        LotMakingAssignment::create(['part_id' => $part->id, 'machine_id' => $machineB->id, 'proses' => 2]);

        $cycle = LotMakingCycle::create(['part_no' => 'P1', 'lot_produksi' => 50, 'source' => 'scan', 'completed_at' => now()]);
        LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 50, 'lot_making_cycle_id' => $cycle->id]);

        $html = $this->get(route('andon-lot-making.show'))->getContent();

        $this->assertStringContainsString('PT91, PT92', $html);
    }

    public function test_the_planning_roller_drops_a_cycle_once_every_proses_step_is_closed(): void
    {
        // The roller is the active Kanban queue, not a history log — once
        // every one of a lot's proses steps is closed, it's done and no
        // longer belongs there.
        $part = Part::create(['part_no' => 'P1']);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 50, 'slot' => 1, 'jumlah_proses' => 1]);
        $machine = Machine::create(['name' => 'PT91']);
        $cycle = LotMakingCycle::create([
            'part_no' => 'P1', 'lot_produksi' => 50, 'source' => 'scan', 'completed_at' => now(),
        ]);
        $planning = LotMakingPlanning::create([
            'part_id' => $part->id, 'lot' => 50, 'lot_making_cycle_id' => $cycle->id,
        ]);
        $planning->assignments()->create(['proses' => 1, 'machine_id' => $machine->id, 'shift' => 1, 'finished_at' => now()]);

        $html = $this->get(route('andon-lot-making.show'))->getContent();

        $this->assertStringNotContainsString('Lot 50', $html);
    }

    public function test_the_planning_roller_shows_open_and_in_progress_as_separate_sections(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        $board = PatternBoard::create(['name' => 'A']);
        $machine = Machine::create(['name' => 'PT91']);
        $pattern = Pattern::create([
            'pattern_board_id' => $board->id, 'machine_id' => $machine->id, 'part_id' => $part->id,
            'shift' => 1, 'proses' => 1, 'loading_time' => 30, 'jumlah_proses' => 1, 'dandori' => 0, 'total_kanban' => 1,
        ]);
        $openCycle = LotMakingCycle::create(['part_no' => 'OPEN-1', 'lot_produksi' => 10, 'source' => 'scan', 'completed_at' => now()]);
        LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 10, 'lot_making_cycle_id' => $openCycle->id]);
        $progressCycle = LotMakingCycle::create(['part_no' => 'PROGRESS-1', 'lot_produksi' => 20, 'source' => 'scan', 'completed_at' => now()]);
        $progressPlanning = LotMakingPlanning::create(['part_id' => $part->id, 'lot' => 20, 'lot_making_cycle_id' => $progressCycle->id]);
        $progressPlanning->assignments()->create(['proses' => 1, 'machine_id' => $machine->id, 'shift' => 1, 'pattern_id' => $pattern->id]);

        $html = $this->get(route('andon-lot-making.show'))->getContent();

        $this->assertStringContainsString('ANTRIAN KANBAN', $html);
        $this->assertStringContainsString('OPEN', $html);
        $this->assertStringContainsString('IN PROGRESS', $html);
        // The Open header must come before the In Progress one in the markup.
        $this->assertLessThan(strpos($html, 'IN PROGRESS'), strpos($html, '>'.__('OPEN').'<'));
    }

    public function test_the_planning_roller_shows_nothing_extra_for_a_cycle_without_a_planning_row(): void
    {
        // A cycle logged before Lot Making Planning existed has no linked
        // row — treated as still-open, same as a fresh one.
        LotMakingCycle::create(['part_no' => 'P1', 'lot_produksi' => 50, 'source' => 'scan', 'completed_at' => now()]);

        $this->get(route('andon-lot-making.show'))
            ->assertOk()
            ->assertSee('P1');
    }

    public function test_it_shows_a_placeholder_when_there_is_no_data(): void
    {
        $this->get(route('andon-lot-making.show'))
            ->assertSee('Belum ada data lot making.');
    }

    public function test_slot_values_are_slot_fix_with_the_remainder_on_the_last_column(): void
    {
        // Matches the reference sheet: lot 50, slot 8 -> avg 6.25 -> fix 7.
        // 7 columns of 7 (=49) then a final column of 1 to reach 50.
        $part = Part::create(['part_no' => '61367-0D150']);
        LotMaking::create(['no' => 1, 'row' => '1', 'kolom' => '1', 'part_id' => $part->id, 'lot_produksi' => 50, 'slot' => 8]);

        $html = $this->normalize($this->get(route('andon-lot-making.show'))->getContent());

        $this->assertStringContainsString('61367-0D150', $html);
        // 7 sevens then a 1 — assert the values appear in the right order.
        $this->assertSeeInOrder($html, ['>7<', '>7<', '>7<', '>7<', '>7<', '>7<', '>7<', '>1<']);
    }

    public function test_slot_values_are_unbroken_when_lot_produksi_divides_evenly(): void
    {
        $part = Part::create(['part_no' => 'EVEN-1']);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 56, 'slot' => 8]);

        $html = $this->normalize($this->get(route('andon-lot-making.show'))->getContent());

        // 56 / 8 = 7 exactly — every column (including the last) is 7.
        $this->assertSame(8, substr_count($html, '>7<'));
    }

    public function test_slot_values_never_go_negative_when_slot_exceeds_lot_produksi(): void
    {
        // 8 slots for only 5 pieces — slot_fix is 1, so 5 columns get 1 and
        // the remaining 3 columns get 0, never a negative last column.
        $part = Part::create(['part_no' => 'TIGHT-1']);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 5, 'slot' => 8]);

        $html = $this->normalize($this->get(route('andon-lot-making.show'))->getContent());

        $this->assertStringNotContainsString('>-', $html);
        $this->assertSame(5, substr_count($html, '>1<'));
        $this->assertSame(3, substr_count($html, '>0<'));
    }

    public function test_parts_in_the_same_row_are_ordered_by_kolom(): void
    {
        $a = Part::create(['part_no' => 'PART-A']);
        $b = Part::create(['part_no' => 'PART-B']);
        $c = Part::create(['part_no' => 'PART-C']);
        LotMaking::create(['no' => 3, 'row' => '1', 'kolom' => '3', 'part_id' => $c->id]);
        LotMaking::create(['no' => 1, 'row' => '1', 'kolom' => '1', 'part_id' => $a->id]);
        LotMaking::create(['no' => 2, 'row' => '1', 'kolom' => '2', 'part_id' => $b->id]);

        $html = $this->get(route('andon-lot-making.show'))->getContent();

        $this->assertSeeInOrder($html, ['PART-A', 'PART-B', 'PART-C']);
    }

    public function test_rows_are_ordered_by_row_number_not_by_no(): void
    {
        // `no` is a free-form per-part label — it's explicitly allowed to be
        // scrambled and must never decide where a row band lands. Row 3
        // carries a high `no` and row 4 a low one; row 3 must still render
        // first, purely because "3" < "4".
        $row3 = Part::create(['part_no' => 'ROW-3']);
        $row4 = Part::create(['part_no' => 'ROW-4']);
        LotMaking::create(['no' => 99, 'row' => '3', 'kolom' => '3', 'part_id' => $row3->id]);
        LotMaking::create(['no' => 1, 'row' => '4', 'kolom' => '3', 'part_id' => $row4->id]);

        $html = $this->get(route('andon-lot-making.show'))->getContent();

        $this->assertSeeInOrder($html, ['ROW-3', 'ROW-4']);
    }

    public function test_row_and_kolom_sort_numerically_not_alphabetically(): void
    {
        // Plain string order would put "10" before "2" — row/kolom are
        // free-text, so this has to be a natural (numeric-aware) comparison.
        $row2 = Part::create(['part_no' => 'ROW-2']);
        $row10 = Part::create(['part_no' => 'ROW-10']);
        $kolom2 = Part::create(['part_no' => 'KOLOM-2']);
        $kolom10 = Part::create(['part_no' => 'KOLOM-10']);
        LotMaking::create(['row' => '10', 'part_id' => $row10->id]);
        LotMaking::create(['row' => '2', 'part_id' => $row2->id]);
        LotMaking::create(['row' => 'SAMEROW', 'kolom' => '10', 'part_id' => $kolom10->id]);
        LotMaking::create(['row' => 'SAMEROW', 'kolom' => '2', 'part_id' => $kolom2->id]);

        $html = $this->get(route('andon-lot-making.show'))->getContent();

        $this->assertSeeInOrder($html, ['ROW-2', 'ROW-10']);
        $this->assertSeeInOrder($html, ['KOLOM-2', 'KOLOM-10']);
    }

    public function test_parts_without_a_row_each_get_their_own_band_instead_of_merging(): void
    {
        $a = Part::create(['part_no' => 'SOLO-A']);
        $b = Part::create(['part_no' => 'SOLO-B']);
        LotMaking::create(['part_id' => $a->id, 'row' => null]);
        LotMaking::create(['part_id' => $b->id, 'row' => null]);

        $html = $this->get(route('andon-lot-making.show'))->getContent();

        $this->assertStringContainsString('SOLO-A', $html);
        $this->assertStringContainsString('SOLO-B', $html);
    }

    /**
     * Blade's indentation puts each cell's value on its own line, so a raw
     * ">7<" never appears literally — collapse whitespace that touches a tag
     * boundary so the simple substring checks above still work.
     */
    private function normalize(string $html): string
    {
        return preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], $html);
    }

    private function assertSeeInOrder(string $html, array $needles): void
    {
        $position = -1;

        foreach ($needles as $needle) {
            $found = strpos($html, $needle, $position + 1);
            $this->assertNotFalse($found, "Expected to find \"{$needle}\" after position {$position}.");
            $position = $found;
        }
    }
}
