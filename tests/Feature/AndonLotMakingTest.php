<?php

namespace Tests\Feature;

use App\Models\LotMaking;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AndonLotMakingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_board_is_public(): void
    {
        $this->get(route('andon-lot-making.show'))
            ->assertOk()
            ->assertSee('LOT MAKING LINE 9');
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

    public function test_rows_are_ordered_by_their_lowest_no(): void
    {
        $a = Part::create(['part_no' => 'ROW-28']);
        $b = Part::create(['part_no' => 'ROW-1']);
        LotMaking::create(['no' => 28, 'row' => '28', 'part_id' => $a->id]);
        LotMaking::create(['no' => 1, 'row' => '1', 'part_id' => $b->id]);

        $html = $this->get(route('andon-lot-making.show'))->getContent();

        $this->assertSeeInOrder($html, ['ROW-1', 'ROW-28']);
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
