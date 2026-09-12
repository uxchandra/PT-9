<?php

namespace Tests\Feature;

use App\Models\LotMaking;
use App\Models\LotMakingCycle;
use App\Models\LotMakingScan;
use App\Models\Part;
use App\Models\StockSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Lot Making 2" — same grid as Lot Making 1, but ticks come straight from
 * the SOS kanban-pull/stock-decrease feed instead of an operator scan (see
 * LotMakingBoard::data('demand') and AndonLotMakingController::showDemand).
 */
class AndonLotMakingDemandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demand_board_is_public(): void
    {
        $this->get(route('andon-lot-making.demand'))
            ->assertOk()
            ->assertSee('LOT MAKING  LINE 9');
    }

    public function test_ticks_come_straight_from_stock_decrease_with_no_scan_involved(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');

        $part = Part::create(['part_no' => '61367-0D150', 'qty_kbn' => 1]);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 50, 'slot' => 8]);

        StockSnapshot::create(['part_no' => '61367-0D150', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);
        StockSnapshot::create(['part_no' => '61367-0D150', 'stock' => 96, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:30')]); // -4 kanban

        $html = $this->normalize($this->get(route('andon-lot-making.demand'))->getContent());

        $this->assertStringContainsString('61367-0D150', $html);
        // 4 ticks landed in the first slot column purely from the stock feed.
        $this->assertStringContainsString('>4<', $html);
        $this->assertSame(0, LotMakingScan::count());

        Carbon::setTestNow();
    }

    public function test_lot_making_1_and_lot_making_2_rollers_never_mix_sources(): void
    {
        LotMakingCycle::create(['part_no' => 'SCAN-PART', 'lot_produksi' => 20, 'source' => 'scan', 'completed_at' => now()]);
        LotMakingCycle::create(['part_no' => 'DEMAND-PART', 'lot_produksi' => 30, 'source' => 'demand', 'completed_at' => now()]);

        $scanRoller = $this->get(route('andon-lot-making.show'))->getContent();
        $demandRoller = $this->get(route('andon-lot-making.demand'))->getContent();

        $this->assertStringContainsString('SCAN-PART', $scanRoller);
        $this->assertStringNotContainsString('DEMAND-PART', $scanRoller);

        $this->assertStringContainsString('DEMAND-PART', $demandRoller);
        $this->assertStringNotContainsString('SCAN-PART', $demandRoller);
    }

    /**
     * Blade's indentation puts each cell's value on its own line, so a raw
     * ">4<" never appears literally — collapse whitespace that touches a tag
     * boundary so the simple substring checks above still work.
     */
    private function normalize(string $html): string
    {
        return preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], $html);
    }
}
