<?php

namespace Tests\Feature;

use App\Models\KeseiPart;
use App\Models\KeseiScan;
use App\Models\Part;
use App\Models\StockSnapshot;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScannerTest extends TestCase
{
    use RefreshDatabase;

    private function scannerUser(): User
    {
        (new RolePermissionSeeder)->run();

        $user = User::factory()->create();
        $user->assignRole('scanner');

        return $user;
    }

    public function test_a_scanner_user_hitting_the_dashboard_is_bounced_to_the_scanner(): void
    {
        // Login always aims for /dashboard; a scanner user must not dead-end there.
        $this->actingAs($this->scannerUser())
            ->get(route('dashboard'))
            ->assertRedirect(route('scanner.dashboard'));
    }

    public function test_scanner_dashboard_shows_the_title_and_both_location_cards(): void
    {
        $this->actingAs($this->scannerUser())
            ->get(route('scanner.dashboard'))
            ->assertOk()
            ->assertSee('KESEI KANBAN')
            ->assertSee('Finish Goods')
            ->assertSee('Store 3');
    }

    public function test_a_non_scanner_user_without_dashboard_access_still_gets_403(): void
    {
        (new RolePermissionSeeder)->run();
        $user = User::factory()->create(); // no roles at all

        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
        $this->actingAs($user)->get(route('scanner.dashboard'))->assertForbidden();
    }

    public function test_staff_still_sees_the_normal_dashboard(): void
    {
        (new RolePermissionSeeder)->run();
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff)->get(route('dashboard'))->assertOk();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('scanner.dashboard'))->assertRedirect(route('login'));
    }

    public function test_a_location_page_shows_the_location_and_a_keyboardless_scan_input(): void
    {
        $this->actingAs($this->scannerUser())
            ->get(route('scanner.location', 'finish-goods'))
            ->assertOk()
            ->assertSee('FINISH GOODS')
            ->assertSee('id="scan-input"', false)
            ->assertSee('inputmode="none"', false); // hardware wedge, no soft keyboard
    }

    public function test_an_unknown_location_is_404(): void
    {
        $this->actingAs($this->scannerUser())
            ->get(route('scanner.location', 'warehouse-x'))
            ->assertNotFound();
    }

    /** A Kesei part at FINISH GOODS level with a 4-kanban stock decrease. */
    private function pullablePart(string $partNo = '57183-BZ010'): KeseiPart
    {
        Carbon::setTestNow('2026-09-15 10:00:00');

        $part = Part::create(['part_no' => $partNo, 'qty_kbn' => 1]);
        $kesei = KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);

        StockSnapshot::create(['part_no' => $partNo, 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);
        StockSnapshot::create(['part_no' => $partNo, 'stock' => 96, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:30')]); // -4

        return $kesei;
    }

    public function test_finish_goods_pull_list_shows_the_part_and_its_needed_kanban(): void
    {
        $this->pullablePart();

        $this->actingAs($this->scannerUser())
            ->get(route('scanner.location', 'finish-goods'))
            ->assertOk()
            ->assertSee('57183-BZ010')
            ->assertSee('/ 4');

        Carbon::setTestNow();
    }

    public function test_a_valid_scan_is_recorded_and_returns_progress(): void
    {
        $this->pullablePart();

        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.scan', 'finish-goods'), ['code' => 'S9 09 I 26 A_8_57183-BZ010_1'])
            ->assertOk()
            ->assertJson(['ok' => true, 'part_no' => '57183-BZ010', 'scanned' => 1, 'needed' => 4, 'remaining' => 3]);

        $this->assertDatabaseHas('kesei_scans', [
            'part_no' => '57183-BZ010',
            'location' => 'finish-goods',
            'raw' => 'S9 09 I 26 A_8_57183-BZ010_1',
        ]);

        Carbon::setTestNow();
    }

    public function test_finish_goods_target_rolls_with_the_15_minute_stock_feed(): void
    {
        // needed_new = needed_old - scanned + decrease  ->  10 - 2 + 3 = 11, scanned resets.
        Carbon::setTestNow('2026-09-15 13:20:00');
        KeseiPart::create([
            'part_id' => Part::create(['part_no' => 'FG-ROLL', 'qty_kbn' => 1])->id,
            'level' => 'FINISH GOODS', 'urutan' => 1,
        ]);

        StockSnapshot::create(['part_no' => 'FG-ROLL', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 12:45')]);
        StockSnapshot::create(['part_no' => 'FG-ROLL', 'stock' => 90, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 13:00')]);  // -10
        // two scans in the 13:00 window
        KeseiScan::create(['part_no' => 'FG-ROLL', 'location' => 'finish-goods', 'raw' => 'x', 'scanned_at' => Carbon::parse('2026-09-15 13:05')]);
        KeseiScan::create(['part_no' => 'FG-ROLL', 'location' => 'finish-goods', 'raw' => 'x', 'scanned_at' => Carbon::parse('2026-09-15 13:10')]);
        // fresh 15-min capture, another -3
        StockSnapshot::create(['part_no' => 'FG-ROLL', 'stock' => 87, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 13:15')]);  // -3

        $this->actingAs($this->scannerUser())
            ->get(route('scanner.location', 'finish-goods'))
            ->assertOk()
            ->assertSee('FG-ROLL')
            ->assertSee('/ 11')
            ->assertSee('last update')
            ->assertSee('13:15');

        Carbon::setTestNow();
    }

    public function test_a_manually_set_pulling_command_replaces_a_target_already_computed_from_old_decreases(): void
    {
        // Same fixture as pullablePart() but built inline so we can set the
        // baseline strictly AFTER the -4 stock decrease has already
        // happened — reproduces the reported bug where setting Perintah
        // Pulling to 15 left the page showing the old computed 18 (adding
        // on top) instead of replacing it outright.
        Carbon::setTestNow('2026-09-15 10:00:00');
        $part = Part::create(['part_no' => 'PW-REPLACE', 'qty_kbn' => 1]);
        $kesei = KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);

        StockSnapshot::create(['part_no' => 'PW-REPLACE', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);
        StockSnapshot::create(['part_no' => 'PW-REPLACE', 'stock' => 96, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:30')]); // -4, so needed would be 4

        Carbon::setTestNow('2026-09-15 10:05:00');
        $kesei->update(['pulling_command' => 15, 'pulling_command_set_at' => now()]);

        $this->actingAs($this->scannerUser())
            ->get(route('scanner.location', 'finish-goods'))
            ->assertOk()
            ->assertSee('PW-REPLACE')
            ->assertSee('/ 15')
            ->assertDontSee('/ 19'); // the old bug: 4 (stale decrease) + 15 (baseline)

        Carbon::setTestNow();
    }

    public function test_a_manually_set_pulling_command_seeds_the_target_even_without_a_stock_decrease(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $part = Part::create(['part_no' => 'PW-1', 'qty_kbn' => 1]);
        $kesei = KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);
        $kesei->update(['pulling_command' => 14, 'pulling_command_set_at' => now()]);
        // No StockSnapshot at all — the target comes purely from the manual
        // baseline, not a computed decrease.

        $this->actingAs($this->scannerUser())
            ->get(route('scanner.location', 'finish-goods'))
            ->assertOk()
            ->assertSee('PW-1')
            ->assertSee('/ 14');

        Carbon::setTestNow();
    }

    public function test_a_manually_set_pulling_command_still_counts_down_as_scans_come_in(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $part = Part::create(['part_no' => 'PW-2', 'qty_kbn' => 1]);
        $kesei = KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);
        $kesei->update(['pulling_command' => 3, 'pulling_command_set_at' => now()]);

        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.scan', 'finish-goods'), ['code' => 'x_x_PW-2_1'])
            ->assertOk()
            ->assertJson(['ok' => true, 'part_no' => 'PW-2', 'scanned' => 1, 'needed' => 3, 'remaining' => 2]);

        Carbon::setTestNow();
    }

    public function test_a_pulling_command_set_before_the_last_closing_no_longer_contributes(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $part = Part::create(['part_no' => 'PW-3', 'qty_kbn' => 1]);
        $kesei = KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);
        // Set well in the past, before the closing below — already folded
        // away along with the rest of that old pile.
        $kesei->update(['pulling_command' => 14, 'pulling_command_set_at' => Carbon::parse('2026-09-14 08:00:00')]);
        $kesei->addClosing('09:00', KeseiPart::CLOSING_END_OF_DAY);

        // No stock decrease and the baseline already folded away — the row
        // has zero demand, same as any other unassigned part, so it drops
        // off the list entirely.
        $this->actingAs($this->scannerUser())
            ->get(route('scanner.location', 'finish-goods'))
            ->assertOk()
            ->assertDontSee('PW-3');

        Carbon::setTestNow();
    }

    public function test_a_part_not_on_the_pull_list_is_rejected(): void
    {
        $this->pullablePart();

        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.scan', 'finish-goods'), ['code' => 'S9 09 I 26 A_8_99999-ZZ999_1'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertDatabaseCount('kesei_scans', 0);

        Carbon::setTestNow();
    }

    public function test_store_3_lists_every_part_of_that_level_with_no_target(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        KeseiPart::create([
            'part_id' => Part::create(['part_no' => 'ST3-001', 'qty_kbn' => 1])->id,
            'level' => 'STORE 3', 'urutan' => 1,
        ]);
        // No stock change at all — still listed for Store 3.

        $this->actingAs($this->scannerUser())
            ->get(route('scanner.location', 'store-3'))
            ->assertOk()
            ->assertSee('ST3-001')
            ->assertSee('Part Store 3'); // free-mode header, no per-part target

        Carbon::setTestNow();
    }

    public function test_store_3_allows_unlimited_scans_of_a_listed_part(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        KeseiPart::create([
            'part_id' => Part::create(['part_no' => 'ST3-001'])->id,
            'level' => 'STORE 3', 'urutan' => 1,
        ]);

        foreach ([1, 2, 3] as $n) {
            $this->actingAs($this->scannerUser())
                ->postJson(route('scanner.scan', 'store-3'), ['code' => 'x_x_ST3-001_1'])
                ->assertOk()
                ->assertJson(['ok' => true, 'part_no' => 'ST3-001', 'scanned' => $n, 'needed' => null, 'remaining' => null]);
        }

        $this->assertDatabaseCount('kesei_scans', 3);

        Carbon::setTestNow();
    }

    public function test_store_3_rejects_a_part_that_is_not_store_3(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        KeseiPart::create([
            'part_id' => Part::create(['part_no' => 'ST3-001'])->id,
            'level' => 'STORE 3', 'urutan' => 1,
        ]);
        KeseiPart::create([
            'part_id' => Part::create(['part_no' => 'FG-9'])->id,
            'level' => 'FINISH GOODS', 'urutan' => 2,
        ]);

        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.scan', 'store-3'), ['code' => 'x_x_FG-9_1'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertDatabaseCount('kesei_scans', 0);

        Carbon::setTestNow();
    }

    public function test_scanning_beyond_the_needed_qty_is_rejected(): void
    {
        $this->pullablePart();

        for ($i = 0; $i < 4; $i++) {
            KeseiScan::create(['part_no' => '57183-BZ010', 'location' => 'finish-goods', 'raw' => 'x', 'scanned_at' => now()]);
        }

        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.scan', 'finish-goods'), ['code' => 'S9 09 I 26 A_8_57183-BZ010_1'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertDatabaseCount('kesei_scans', 4);

        Carbon::setTestNow();
    }

    public function test_an_unparseable_qr_is_rejected(): void
    {
        $this->pullablePart();

        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.scan', 'finish-goods'), ['code' => 'garbage'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        Carbon::setTestNow();
    }

    public function test_a_scan_becomes_a_red_tick_on_the_scan_andon_board(): void
    {
        $this->pullablePart();
        KeseiScan::create([
            'part_no' => '57183-BZ010', 'location' => 'finish-goods', 'raw' => 'x',
            'scanned_at' => Carbon::parse('2026-09-15 09:30'),
        ]);

        $html = $this->get(route('andon-kesei.scan'))->getContent();

        $this->assertStringContainsString('KESEI LINE 9', $html);
        $this->assertStringContainsString('stok turun 1 kanban (1 pcs)', $html);

        // The API-stock board must NOT show that tick.
        $stockHtml = $this->get(route('andon-kesei.show'))->getContent();
        $this->assertStringContainsString('stok turun 4 kanban (4 pcs)', $stockHtml); // the stock decrease
        $this->assertStringNotContainsString('stok turun 1 kanban (1 pcs)', $stockHtml);

        Carbon::setTestNow();
    }

    public function test_several_scans_in_the_same_minute_combine_into_one_tick(): void
    {
        $this->pullablePart();

        // Three scans landing in the same clock-face minute would otherwise
        // stack invisibly on top of each other — they must combine into one
        // tick worth 3 kanban instead.
        foreach (['09:30:01', '09:30:20', '09:30:45'] as $time) {
            KeseiScan::create([
                'part_no' => '57183-BZ010', 'location' => 'finish-goods', 'raw' => 'x',
                'scanned_at' => Carbon::parse("2026-09-15 {$time}"),
            ]);
        }

        $html = $this->get(route('andon-kesei.scan'))->getContent();

        $this->assertStringContainsString('stok turun 3 kanban (3 pcs)', $html);
        $this->assertStringNotContainsString('stok turun 1 kanban (1 pcs)', $html);

        Carbon::setTestNow();
    }
}
