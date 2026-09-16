<?php

namespace Tests\Feature;

use App\Models\KeseiPart;
use App\Models\LotMaking;
use App\Models\LotMakingCycle;
use App\Models\LotMakingScan;
use App\Models\Part;
use App\Models\StockSnapshot;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LotMakingScanTest extends TestCase
{
    use RefreshDatabase;

    private function scannerUser(): User
    {
        (new RolePermissionSeeder)->run();

        $user = User::factory()->create();
        $user->assignRole('scanner');

        return $user;
    }

    private function adminUser(): User
    {
        (new RolePermissionSeeder)->run();

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_scanner_dashboard_does_not_list_a_separate_lot_making_card(): void
    {
        $this->actingAs($this->scannerUser())
            ->get(route('scanner.dashboard'))
            ->assertOk()
            ->assertSee('Finish Goods')
            ->assertSee('Store 3')
            ->assertDontSee('Lot Making');
    }

    public function test_a_lot_making_part_with_finish_goods_level_is_rejected_at_the_lot_making_slug(): void
    {
        // The 'lot-making' slug is no longer a valid scanner card at all —
        // every Lot Making part is grouped under Finish Goods/Store 3 by its
        // own level instead.
        $this->actingAs($this->scannerUser())
            ->get(route('scanner.location', 'lot-making'))
            ->assertNotFound();
    }

    public function test_a_lot_making_part_shows_up_on_the_finish_goods_card_by_its_own_level(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $part = Part::create(['part_no' => '61367-0D150', 'qty_kbn' => 1]);
        LotMaking::create(['part_id' => $part->id, 'level' => 'finish-goods', 'lot_produksi' => 50, 'slot' => 8]);

        StockSnapshot::create(['part_no' => '61367-0D150', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);
        StockSnapshot::create(['part_no' => '61367-0D150', 'stock' => 96, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:30')]); // -4

        $this->actingAs($this->scannerUser())
            ->get(route('scanner.location', 'finish-goods'))
            ->assertOk()
            ->assertSee('61367-0D150')
            ->assertSee('/ 4');

        Carbon::setTestNow();
    }

    public function test_a_lot_making_part_with_no_level_does_not_show_up_on_any_card(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $part = Part::create(['part_no' => 'NO-LEVEL', 'qty_kbn' => 1]);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 50, 'slot' => 8]);

        StockSnapshot::create(['part_no' => 'NO-LEVEL', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);
        StockSnapshot::create(['part_no' => 'NO-LEVEL', 'stock' => 96, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:30')]);

        $this->actingAs($this->scannerUser())
            ->get(route('scanner.location', 'finish-goods'))
            ->assertOk()
            ->assertDontSee('NO-LEVEL');

        $this->actingAs($this->scannerUser())
            ->get(route('scanner.location', 'store-3'))
            ->assertOk()
            ->assertDontSee('NO-LEVEL');

        Carbon::setTestNow();
    }

    public function test_finish_goods_merges_kesei_and_lot_making_parts_on_the_same_card(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');

        $keseiPart = Part::create(['part_no' => 'KESEI-FG', 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $keseiPart->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);
        StockSnapshot::create(['part_no' => 'KESEI-FG', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);
        StockSnapshot::create(['part_no' => 'KESEI-FG', 'stock' => 98, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:30')]); // -2

        $lotMakingPart = Part::create(['part_no' => 'LM-FG', 'qty_kbn' => 1]);
        LotMaking::create(['part_id' => $lotMakingPart->id, 'level' => 'finish-goods', 'lot_produksi' => 50, 'slot' => 8]);
        StockSnapshot::create(['part_no' => 'LM-FG', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);
        StockSnapshot::create(['part_no' => 'LM-FG', 'stock' => 96, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:30')]); // -4

        $this->actingAs($this->scannerUser())
            ->get(route('scanner.location', 'finish-goods'))
            ->assertOk()
            ->assertSee('KESEI-FG')
            ->assertSee('LM-FG');

        Carbon::setTestNow();
    }

    public function test_a_valid_scan_is_recorded_and_returns_progress(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $part = Part::create(['part_no' => 'P1', 'qty_kbn' => 1]);
        LotMaking::create(['part_id' => $part->id, 'level' => 'finish-goods', 'lot_produksi' => 50, 'slot' => 8]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 96, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:30')]); // -4

        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.scan', 'finish-goods'), ['code' => 'S9 09 I 26 A_8_P1_1'])
            ->assertOk()
            ->assertJson(['ok' => true, 'part_no' => 'P1', 'scanned' => 1, 'needed' => 4, 'remaining' => 3]);

        $this->assertDatabaseHas('lot_making_scans', ['part_no' => 'P1', 'raw' => 'S9 09 I 26 A_8_P1_1']);

        Carbon::setTestNow();
    }

    public function test_a_part_not_in_lot_making_or_kesei_is_rejected(): void
    {
        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.scan', 'finish-goods'), ['code' => 'S9 09 I 26 A_8_UNKNOWN_1'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertDatabaseCount('lot_making_scans', 0);
    }

    public function test_an_unparseable_qr_is_rejected(): void
    {
        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.scan', 'finish-goods'), ['code' => 'garbage'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_a_lot_making_part_cannot_be_scanned_from_the_wrong_card(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $part = Part::create(['part_no' => 'P1', 'qty_kbn' => 1]);
        LotMaking::create(['part_id' => $part->id, 'level' => 'finish-goods', 'lot_produksi' => 50, 'slot' => 8]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 96, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:30')]);

        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.scan', 'store-3'), ['code' => 'x_x_P1_1'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertDatabaseCount('lot_making_scans', 0);

        Carbon::setTestNow();
    }

    public function test_scanning_beyond_the_needed_qty_is_rejected(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $part = Part::create(['part_no' => 'P1', 'qty_kbn' => 1]);
        LotMaking::create(['part_id' => $part->id, 'level' => 'finish-goods', 'lot_produksi' => 50, 'slot' => 8]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 96, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:30')]); // -4 kanban

        for ($i = 0; $i < 4; $i++) {
            LotMakingScan::create(['part_no' => 'P1', 'raw' => 'x', 'scanned_at' => now()]);
        }

        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.scan', 'finish-goods'), ['code' => 'x_x_P1_1'])
            ->assertStatus(422);

        Carbon::setTestNow();
    }

    public function test_scans_fill_slot_columns_and_complete_a_cycle_on_the_andon_board(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $part = Part::create(['part_no' => 'P1', 'qty_kbn' => 1]);
        // slot_fix = ceil(5/5) = 1 -> 5 columns, each with capacity 1.
        LotMaking::create(['part_id' => $part->id, 'level' => 'finish-goods', 'lot_produksi' => 5, 'slot' => 5]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 0, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:30')]); // plenty of demand

        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($this->scannerUser())
                ->postJson(route('scanner.scan', 'finish-goods'), ['code' => 'x_x_P1_1'])
                ->assertOk()
                ->assertJson(['ok' => true]);
        }

        // 4 of the 5 (capacity-1) columns now each have one red tick — no cycle yet.
        $this->assertSame(0, LotMakingCycle::count());
        $html = $this->get(route('andon-lot-making.show'))->getContent();
        $this->assertSame(4, substr_count($html, 'bg-red-500'));

        // The 5th scan fills the last column and completes the cycle.
        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.scan', 'finish-goods'), ['code' => 'x_x_P1_1'])
            ->assertOk();

        $this->assertSame(1, LotMakingCycle::count());
        $cycle = LotMakingCycle::first();
        $this->assertSame('P1', $cycle->part_no);
        $this->assertSame(5, $cycle->lot_produksi);

        // The roller panel shows the completed cycle.
        $rollerHtml = $this->get(route('andon-lot-making.show'))->getContent();
        $this->assertStringContainsString('P1', $rollerHtml);
        $this->assertStringContainsString('Lot 5', $rollerHtml);

        // A 6th scan starts a fresh cycle rather than continuing to pile up.
        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.scan', 'finish-goods'), ['code' => 'x_x_P1_1'])
            ->assertOk();
        $this->assertSame(1, LotMakingCycle::count());

        Carbon::setTestNow();
    }

    public function test_the_andon_board_shows_a_running_total_of_ticks_per_column(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $part = Part::create(['part_no' => 'P1', 'qty_kbn' => 1]);
        // slot_fix = ceil(20/5) = 4 -> 5 columns of capacity 4 each.
        LotMaking::create(['part_id' => $part->id, 'level' => 'finish-goods', 'lot_produksi' => 20, 'slot' => 5]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 0, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:30')]);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->scannerUser())
                ->postJson(route('scanner.scan', 'finish-goods'), ['code' => 'x_x_P1_1'])
                ->assertOk();
        }

        $html = preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], $this->get(route('andon-lot-making.show'))->getContent());

        // First column has 3 of 4 filled — the running-total row shows "3"
        // for that column and stays blank for the untouched ones.
        $this->assertSame(1, substr_count($html, '>3<'));
        $this->assertSame(5, substr_count($html, '>4<')); // all 5 columns' capacity numbers

        Carbon::setTestNow();
    }

    public function test_history_scan_page_requires_manage_lot_making_permission(): void
    {
        $this->get(route('lot-making-scans.index'))->assertRedirect(route('login'));

        (new RolePermissionSeeder)->run();
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $this->actingAs($staff)->get(route('lot-making-scans.index'))->assertForbidden();

        $this->actingAs($this->adminUser())->get(route('lot-making-scans.index'))->assertOk();
    }

    public function test_history_scan_lists_scans_newest_first_and_filters_by_part(): void
    {
        LotMakingScan::create(['part_no' => 'AAA-111', 'raw' => 'x_x_AAA-111_1', 'scanned_at' => Carbon::parse('2026-09-10 08:00')]);
        LotMakingScan::create(['part_no' => 'BBB-222', 'raw' => 'x_x_BBB-222_1', 'scanned_at' => Carbon::parse('2026-09-10 09:00')]);

        $this->actingAs($this->adminUser())
            ->get(route('lot-making-scans.index'))
            ->assertOk()
            ->assertSee('AAA-111')
            ->assertSee('BBB-222')
            ->assertSeeInOrder(['BBB-222', 'AAA-111']);

        $this->actingAs($this->adminUser())
            ->get(route('lot-making-scans.index', ['q' => 'AAA']))
            ->assertOk()
            ->assertSee('AAA-111')
            ->assertDontSee('BBB-222');
    }
}
