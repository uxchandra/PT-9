<?php

namespace Tests\Feature;

use App\Models\HeijunkaBoxSchedule;
use App\Models\KeseiPart;
use App\Models\KeseiScan;
use App\Models\LotMaking;
use App\Models\Part;
use App\Models\StockSnapshot;
use App\Services\KeseiPull;
use App\Services\LotMakingPull;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HeikinkaAndBoxPullingTest extends TestCase
{
    use RefreshDatabase;

    private function keseiPart(string $partNo, array $slots): Part
    {
        $part = Part::create(['part_no' => $partNo, 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);
        HeijunkaBoxSchedule::create(['part_id' => $part->id, 'cycle_issue' => 'TEST-X', 'slots' => $slots]);

        return $part;
    }

    private function stock(string $partNo, int $stock, string $at): void
    {
        StockSnapshot::create(['part_no' => $partNo, 'stock' => $stock, 'std_min' => 0, 'captured_at' => Carbon::parse($at)]);
    }

    public function test_each_green_box_tick_the_progress_bar_passes_becomes_pulling_demand(): void
    {
        Carbon::setTestNow('2026-09-15 08:15:00');
        $this->keseiPart('BP-1', ['07:10', '08:10', '09:10']);
        $this->stock('BP-1', 100, '2026-09-15 06:00');
        $this->stock('BP-1', 97, '2026-09-15 07:05'); // 3 kanban of backlog

        $row = app(KeseiPull::class)->list('finish-goods')->firstWhere('part_no', 'BP-1');

        // Only 07:10 and 08:10 have been passed by 08:15; 09:10 has not yet.
        $this->assertSame(2, $row['needed']);
        $this->assertSame(2, $row['remaining']);

        // Before the first slot there is nothing to pull at all, even though stock already dropped.
        Carbon::setTestNow('2026-09-15 07:08:00');
        $this->assertNull(app(KeseiPull::class)->list('finish-goods')->firstWhere('part_no', 'BP-1'));

        Carbon::setTestNow();
    }

    public function test_a_scan_after_the_tick_fired_reduces_what_is_remaining(): void
    {
        Carbon::setTestNow('2026-09-15 08:15:00');
        $this->keseiPart('BP-2', ['07:10', '08:10']);
        $this->stock('BP-2', 100, '2026-09-15 06:00');
        $this->stock('BP-2', 98, '2026-09-15 07:05');
        KeseiScan::create(['part_no' => 'BP-2', 'location' => 'finish-goods', 'raw' => 'BP-2', 'scanned_at' => Carbon::parse('2026-09-15 08:12')]);

        $row = app(KeseiPull::class)->list('finish-goods')->firstWhere('part_no', 'BP-2');

        $this->assertSame(2, $row['needed']);
        $this->assertSame(1, $row['scanned']);
        $this->assertSame(1, $row['remaining']);

        Carbon::setTestNow();
    }

    public function test_yesterdays_scans_are_not_netted_off_against_todays_box_ticks(): void
    {
        Carbon::setTestNow('2026-09-15 08:15:00');
        $this->keseiPart('BP-3', ['07:10']);
        $this->stock('BP-3', 100, '2026-09-15 06:00');
        $this->stock('BP-3', 99, '2026-09-15 07:05');
        KeseiScan::create(['part_no' => 'BP-3', 'location' => 'finish-goods', 'raw' => 'BP-3', 'scanned_at' => Carbon::parse('2026-09-14 12:00')]);

        $row = app(KeseiPull::class)->list('finish-goods')->firstWhere('part_no', 'BP-3');

        $this->assertSame(1, $row['needed']);
        $this->assertSame(0, $row['scanned']);

        Carbon::setTestNow();
    }

    public function test_a_part_without_a_box_schedule_keeps_the_lt_per_kbn_pacing(): void
    {
        Carbon::setTestNow('2026-09-15 08:15:00');
        $part = Part::create(['part_no' => 'BP-OLD', 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);
        $this->stock('BP-OLD', 100, '2026-09-15 06:00');
        $this->stock('BP-OLD', 98, '2026-09-15 07:05');

        $row = app(KeseiPull::class)->list('finish-goods')->firstWhere('part_no', 'BP-OLD');

        $this->assertSame(2, $row['needed']);

        Carbon::setTestNow();
    }

    public function test_scan_row_uses_the_box_too(): void
    {
        Carbon::setTestNow('2026-09-15 08:15:00');
        $this->keseiPart('BP-4', ['07:10', '09:10']);
        $this->stock('BP-4', 100, '2026-09-15 06:00');
        $this->stock('BP-4', 98, '2026-09-15 07:05');

        $row = app(KeseiPull::class)->scanRow('finish-goods', 'BP-4');

        $this->assertSame(1, $row['needed']);

        Carbon::setTestNow();
    }

    public function test_lot_making_pulling_is_driven_by_the_box_as_well(): void
    {
        Carbon::setTestNow('2026-09-15 08:15:00');
        $part = Part::create(['part_no' => 'BP-LM', 'qty_kbn' => 1]);
        LotMaking::create(['part_id' => $part->id, 'level' => 'finish-goods']);
        HeijunkaBoxSchedule::create(['part_id' => $part->id, 'cycle_issue' => 'TEST-X', 'slots' => ['07:10', '08:10', '09:10']]);
        $this->stock('BP-LM', 100, '2026-09-15 06:00');
        $this->stock('BP-LM', 97, '2026-09-15 07:05');

        $row = app(LotMakingPull::class)->list('finish-goods')->firstWhere('part_no', 'BP-LM');

        $this->assertSame(2, $row['needed']);
        $this->assertSame(2, app(LotMakingPull::class)->scanRow('BP-LM', 'finish-goods')['needed']);

        Carbon::setTestNow();
    }

    public function test_heikinka_history_shows_the_selected_past_day_and_a_riwayat_badge(): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        $part = Part::create(['part_no' => 'HK-1', 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);
        $this->stock('HK-1', 100, '2026-09-13 06:00');
        $this->stock('HK-1', 99, '2026-09-13 09:00');

        $live = $this->get(route('andon-kesei.heijunka'))->getContent();
        $this->assertStringNotContainsString('RIWAYAT', $live);
        $this->assertStringContainsString('name="date"', $live);

        $history = $this->get(route('andon-kesei.heijunka', ['date' => '2026-09-13']))->getContent();
        $this->assertStringContainsString('RIWAYAT', $history);
        $this->assertStringContainsString('value="2026-09-13"', $history);
        // The tick from that day is on the board (beyond 24h of now, so live drops it).
        $this->assertStringContainsString('stok turun', $history);
        $this->assertStringNotContainsString('stok turun', $live);

        // Today, a future date, or garbage all fall back to live.
        foreach (['2026-09-15', '2026-12-31', 'not-a-date'] as $bad) {
            $this->assertStringNotContainsString('RIWAYAT', $this->get(route('andon-kesei.heijunka', ['date' => $bad]))->getContent());
        }

        Carbon::setTestNow();
    }

    public function test_actual_per_plan_footer_counts_blue_over_every_tick_in_the_hour(): void
    {
        Carbon::setTestNow('2026-09-15 09:30:00');
        $part = Part::create(['part_no' => 'HK-2', 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);
        $this->stock('HK-2', 100, '2026-09-15 07:30');
        $this->stock('HK-2', 98, '2026-09-15 08:15'); // 2 ticks in the 08:00 hour
        KeseiScan::create(['part_no' => 'HK-2', 'location' => 'finish-goods', 'raw' => 'HK-2', 'scanned_at' => Carbon::parse('2026-09-15 08:20')]);

        $html = $this->get(route('andon-kesei.heijunka'))->getContent();

        $this->assertStringContainsString('Actual / Plan', $html);
        $this->assertStringContainsString('1/2', $html);

        Carbon::setTestNow();
    }
}
