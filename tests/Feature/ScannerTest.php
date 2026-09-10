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

    public function test_a_location_page_shows_the_location_and_a_scan_input(): void
    {
        $this->actingAs($this->scannerUser())
            ->get(route('scanner.location', 'finish-goods'))
            ->assertOk()
            ->assertSee('FINISH GOODS')
            ->assertSee('id="scan-input"', false);
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

        $this->assertStringContainsString('KESEI SCAN LINE 9', $html);
        $this->assertStringContainsString('stok turun 1 kanban (1 pcs)', $html);

        // The API-stock board must NOT show that tick.
        $stockHtml = $this->get(route('andon-kesei.show'))->getContent();
        $this->assertStringContainsString('stok turun 4 kanban (4 pcs)', $stockHtml); // the stock decrease
        $this->assertStringNotContainsString('stok turun 1 kanban (1 pcs)', $stockHtml);

        Carbon::setTestNow();
    }
}
