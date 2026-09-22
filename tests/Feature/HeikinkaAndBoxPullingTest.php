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

    public function test_a_scan_within_the_last_24h_nets_off_a_fresh_box_tick_even_if_it_was_the_previous_calendar_day(): void
    {
        // Heijunka Box loops on a rolling 24h window, not the 07:00
        // production-day boundary — a scan 9h15m ago (still "yesterday" by
        // calendar date) is well within that window, so it DOES net off a
        // tick fired today.
        Carbon::setTestNow('2026-09-15 08:15:00');
        $this->keseiPart('BP-3', ['07:10']);
        $this->stock('BP-3', 100, '2026-09-15 06:00');
        $this->stock('BP-3', 99, '2026-09-15 07:05');
        KeseiScan::create(['part_no' => 'BP-3', 'location' => 'finish-goods', 'raw' => 'BP-3', 'scanned_at' => Carbon::parse('2026-09-14 23:00')]);

        $row = app(KeseiPull::class)->list('finish-goods')->firstWhere('part_no', 'BP-3');

        // Fully satisfied — needed and scanned both net to 0, so the row
        // doesn't even show up on the pulling list.
        $this->assertNull($row);

        Carbon::setTestNow();
    }

    public function test_a_scan_older_than_24h_is_not_netted_off_against_a_fresh_box_tick(): void
    {
        Carbon::setTestNow('2026-09-15 08:15:00');
        $this->keseiPart('BP-3B', ['07:10']);
        $this->stock('BP-3B', 100, '2026-09-15 06:00');
        $this->stock('BP-3B', 99, '2026-09-15 07:05');
        // 25h ago — just outside the rolling window.
        KeseiScan::create(['part_no' => 'BP-3B', 'location' => 'finish-goods', 'raw' => 'BP-3B', 'scanned_at' => Carbon::parse('2026-09-14 07:15')]);

        $row = app(KeseiPull::class)->list('finish-goods')->firstWhere('part_no', 'BP-3B');

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

    public function test_heikinka_history_shows_the_selected_past_day_with_its_date_label(): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        $part = Part::create(['part_no' => 'HK-1', 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);
        $this->stock('HK-1', 100, '2026-09-13 06:00');
        $this->stock('HK-1', 99, '2026-09-13 09:00');

        $live = $this->get(route('andon-kesei.heijunka'))->getContent();
        $this->assertStringContainsString('name="date"', $live);
        $this->assertStringContainsString(Carbon::parse('2026-09-15')->locale('id')->translatedFormat('l, d F Y'), $live);

        $history = $this->get(route('andon-kesei.heijunka', ['date' => '2026-09-13']))->getContent();
        $this->assertStringNotContainsString('RIWAYAT', $history);
        $this->assertStringContainsString('value="2026-09-13"', $history);
        $this->assertStringContainsString(Carbon::parse('2026-09-13')->locale('id')->translatedFormat('l, d F Y'), $history);
        // The tick from that day is on the board (beyond 24h of now, so live drops it).
        $this->assertStringContainsString('stok turun', $history);
        $this->assertStringNotContainsString('stok turun', $live);

        // Today, a future date, or garbage all fall back to live.
        foreach (['2026-09-15', '2026-12-31', 'not-a-date'] as $bad) {
            $this->assertStringContainsString('value="2026-09-15"', $this->get(route('andon-kesei.heijunka', ['date' => $bad]))->getContent());
        }

        Carbon::setTestNow();
    }

    public function test_heikinka_history_shows_only_the_already_pulled_box_ticks_at_their_slot_time(): void
    {
        // Heikinka is a pure history of pulling that actually happened — a
        // pending/overdue (not-yet-pulled) box tick has no place in it, only
        // a completed one (blue), at the exact slot time it pulled against.
        Carbon::setTestNow('2026-09-16 12:00:00');
        $this->keseiPart('HK-BOX', ['07:10', '08:10']);
        $this->stock('HK-BOX', 100, '2026-09-15 06:00');
        $this->stock('HK-BOX', 98, '2026-09-15 07:05');
        // Scanned at 07:20 — after the 07:10 slot, so the first tick is blue.
        // The 08:10 tick is never scanned (would be red on Heijunka itself).
        KeseiScan::create(['part_no' => 'HK-BOX', 'location' => 'finish-goods', 'raw' => 'HK-BOX', 'scanned_at' => Carbon::parse('2026-09-15 07:20')]);

        $html = $this->get(route('andon-kesei.heijunka', ['date' => '2026-09-15']))->getContent();
        $panel = substr($html, strpos($html, 'id="kesei-panel-timeline"'));

        // Only the one pulled (blue) tick shows, at its slot time — not the
        // unpulled 08:10 one, and not the 07:05 the stock actually dropped at.
        $this->assertSame(1, substr_count($panel, 'background-color: #3b82f6'));
        $this->assertSame(0, substr_count($panel, 'background-color: #ff3b3b'));
        $this->assertStringContainsString('07:10', $panel);
        $this->assertStringNotContainsString('08:10', $panel);
        $this->assertStringNotContainsString('07:05', $panel);

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
