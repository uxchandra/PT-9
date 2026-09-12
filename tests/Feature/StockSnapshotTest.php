<?php

namespace Tests\Feature;

use App\Models\KeseiPart;
use App\Models\LotMaking;
use App\Models\LotMakingCycle;
use App\Models\LotMakingScan;
use App\Models\Part;
use App\Models\PatternBoard;
use App\Models\PatternGroupItem;
use App\Models\StockSnapshot;
use App\Models\User;
use App\Services\LotMakingDemandCycleTracker;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function authorizedUser(): User
    {
        (new RolePermissionSeeder)->run();

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function fakeFeed(array $rows): void
    {
        Http::fake(['*' => Http::response(['data' => $rows])]);
    }

    private function row(string $partNo, int $stock, string $process = 'TD', int $stdMin = 10): array
    {
        return ['part_no' => $partNo, 'stock' => $stock, 'std_min' => $stdMin, 'process' => $process];
    }

    public function test_capture_only_records_parts_used_by_pattern_or_kesei_with_process_td(): void
    {
        $board = PatternBoard::create(['name' => 'A']);
        $patternPart = Part::create(['part_no' => 'PATTERN-PART']);
        PatternGroupItem::create([
            'pattern_board_id' => $board->id, 'part_id' => $patternPart->id, 'shift' => 1,
            'urutan' => 1, 'lot' => 10, 'loading_time' => 10, 'jumlah_proses' => 1, 'total_kanban' => 1, 'dandori' => 0,
        ]);

        KeseiPart::create(['part_id' => Part::create(['part_no' => 'KESEI-PART'])->id, 'urutan' => 1]);
        KeseiPart::create(['part_id' => Part::create(['part_no' => 'KESEI-FAMILY'])->id, 'stock_source' => 'SRC-X', 'urutan' => 2]);

        $this->fakeFeed([
            $this->row('PATTERN-PART', 40),
            $this->row('PATTERN-PART', 999, 'WELD'),   // wrong process — ignored
            $this->row('KESEI-PART', 25),
            $this->row('SRC-X', 12),                    // a Kesei stock_source, not in Part List
            $this->row('KESEI-FAMILY', 8),
            $this->row('RANDOM-PART', 500),             // not used anywhere — must NOT be recorded
        ]);

        $this->artisan('stock:capture-snapshot')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['PATTERN-PART', 'KESEI-PART', 'SRC-X', 'KESEI-FAMILY'],
            StockSnapshot::pluck('part_no')->all()
        );
        // The wrong-process row did not inflate PATTERN-PART's stock.
        $this->assertSame(40, StockSnapshot::where('part_no', 'PATTERN-PART')->first()->stock);
    }

    public function test_capture_also_records_a_part_used_only_by_lot_making(): void
    {
        $part = Part::create(['part_no' => 'LOT-ONLY']);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 10, 'slot' => 2]);

        $this->fakeFeed([$this->row('LOT-ONLY', 30)]);

        $this->artisan('stock:capture-snapshot')->assertSuccessful();

        $this->assertSame(['LOT-ONLY'], StockSnapshot::pluck('part_no')->all());
    }

    public function test_a_full_lot_of_demand_completes_a_lot_making_2_cycle_on_the_capture_tick(): void
    {
        $part = Part::create(['part_no' => 'DEMAND-1', 'qty_kbn' => 1]);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 4, 'slot' => 2]);

        StockSnapshot::create(['part_no' => 'DEMAND-1', 'stock' => 100, 'std_min' => 0, 'captured_at' => now()->subMinutes(20)]);

        $this->fakeFeed([$this->row('DEMAND-1', 96)]); // -4 pcs / qty_kbn 1 = 4 kanban = exactly one lot

        $this->artisan('stock:capture-snapshot')->assertSuccessful();

        $cycle = LotMakingCycle::where('part_no', 'DEMAND-1')->where('source', 'demand')->first();
        $this->assertNotNull($cycle);
        $this->assertSame(4, $cycle->lot_produksi);
        // No operator ever scanned anything — this is purely a stock-feed event.
        $this->assertSame(0, LotMakingScan::count());
    }

    public function test_several_lots_of_demand_in_one_tick_complete_multiple_lot_making_2_cycles(): void
    {
        $part = Part::create(['part_no' => 'DEMAND-2', 'qty_kbn' => 1]);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 4, 'slot' => 2]);

        StockSnapshot::create(['part_no' => 'DEMAND-2', 'stock' => 100, 'std_min' => 0, 'captured_at' => now()->subMinutes(20)]);

        $this->fakeFeed([$this->row('DEMAND-2', 91)]); // -9 pcs = 9 kanban = 2 full lots + 1 left over

        $this->artisan('stock:capture-snapshot')->assertSuccessful();

        $this->assertSame(2, LotMakingCycle::where('part_no', 'DEMAND-2')->where('source', 'demand')->count());
    }

    public function test_leftover_demand_from_a_multi_lot_tick_carries_forward_instead_of_vanishing(): void
    {
        // A single 15-minute tick can easily carry more than one lot's worth
        // of kanban: 13 pulled against a lot of 5 completes 2 full lots with
        // 3 left over. That 3 must still count toward the *next* lot instead
        // of disappearing because the event it came from already produced
        // completions.
        $part = Part::create(['part_no' => 'DEMAND-4', 'qty_kbn' => 1]);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 5, 'slot' => 2]);

        StockSnapshot::create(['part_no' => 'DEMAND-4', 'stock' => 50, 'std_min' => 0, 'captured_at' => now()->subMinutes(30)]);
        $this->fakeFeed([$this->row('DEMAND-4', 37)]); // -13 kanban = 2 lots + 3 left over

        $this->artisan('stock:capture-snapshot')->assertSuccessful();
        $this->assertSame(2, LotMakingCycle::where('part_no', 'DEMAND-4')->where('source', 'demand')->count());

        $tracker = app(LotMakingDemandCycleTracker::class);
        $this->assertSame(3, $tracker->ticksSinceLastCycle('DEMAND-4'));

        // A further +4 kanban should complete a 3rd lot (3 + 4 = 7 >= 5),
        // leaving 2 pending — proving the carry keeps accumulating correctly
        // across repeated polls, not just surviving a single one.
        StockSnapshot::create(['part_no' => 'DEMAND-4', 'stock' => 33, 'std_min' => 0, 'captured_at' => now()]);
        $tracker->checkForCompletion('DEMAND-4');

        $this->assertSame(3, LotMakingCycle::where('part_no', 'DEMAND-4')->where('source', 'demand')->count());
        $this->assertSame(2, $tracker->ticksSinceLastCycle('DEMAND-4'));
    }

    public function test_lot_making_2_demand_completions_never_touch_the_lot_making_1_scan_tracker(): void
    {
        $part = Part::create(['part_no' => 'DEMAND-3', 'qty_kbn' => 1]);
        LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 4, 'slot' => 2]);

        StockSnapshot::create(['part_no' => 'DEMAND-3', 'stock' => 100, 'std_min' => 0, 'captured_at' => now()->subMinutes(20)]);

        $this->fakeFeed([$this->row('DEMAND-3', 96)]);

        $this->artisan('stock:capture-snapshot')->assertSuccessful();

        $this->assertSame(1, LotMakingCycle::where('part_no', 'DEMAND-3')->where('source', 'demand')->count());
        $this->assertSame(0, LotMakingCycle::where('part_no', 'DEMAND-3')->where('source', 'scan')->count());
    }

    public function test_prune_deletes_snapshots_older_than_7_days(): void
    {
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 1, 'std_min' => 0, 'captured_at' => now()->subDays(9)]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 2, 'std_min' => 0, 'captured_at' => now()->subDays(8)]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 3, 'std_min' => 0, 'captured_at' => now()->subDays(6)]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 4, 'std_min' => 0, 'captured_at' => now()->subHour()]);

        $this->artisan('stock:prune-snapshots')->assertSuccessful();

        $this->assertSame(2, StockSnapshot::count());
        $this->assertSame(0, StockSnapshot::where('captured_at', '<', now()->subDays(7))->count());
    }

    public function test_stock_snapshot_page_requires_the_view_stock_snapshot_permission(): void
    {
        $this->get(route('stock-snapshots.index'))->assertRedirect(route('login'));

        (new RolePermissionSeeder)->run();
        $noPerm = User::factory()->create();
        $noPerm->assignRole(Role::create(['name' => 'nobody']));
        $this->actingAs($noPerm)->get(route('stock-snapshots.index'))->assertForbidden();

        $this->actingAs($this->authorizedUser())->get(route('stock-snapshots.index'))->assertOk();
    }

    public function test_stock_snapshot_page_shows_the_time_by_part_grid_for_the_chosen_day(): void
    {
        // AAA + BBB are monitored (in Kesei); UNWANTED is recorded but not.
        KeseiPart::create(['part_id' => Part::create(['part_no' => 'AAA'])->id, 'urutan' => 1]);
        KeseiPart::create(['part_id' => Part::create(['part_no' => 'BBB'])->id, 'urutan' => 2]);

        $date = '2026-09-08';
        $start = Carbon::parse($date)->setTime(7, 0);
        StockSnapshot::create(['part_no' => 'AAA', 'stock' => 50, 'std_min' => 10, 'captured_at' => $start]);
        StockSnapshot::create(['part_no' => 'BBB', 'stock' => 5, 'std_min' => 10, 'captured_at' => $start]);            // under min
        StockSnapshot::create(['part_no' => 'AAA', 'stock' => 48, 'std_min' => 10, 'captured_at' => $start->copy()->addMinutes(5)]);
        StockSnapshot::create(['part_no' => 'ZZZ', 'stock' => 1, 'std_min' => 0, 'captured_at' => $start->copy()->subDay()]);        // other day — excluded
        StockSnapshot::create(['part_no' => 'UNWANTED', 'stock' => 77, 'std_min' => 0, 'captured_at' => $start]);                    // not in Pattern/Kesei — excluded

        $html = $this->actingAs($this->authorizedUser())
            ->get(route('stock-snapshots.index', ['date' => $date]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('AAA', $html);
        $this->assertStringContainsString('BBB', $html);
        $this->assertStringNotContainsString('ZZZ', $html);       // other day
        $this->assertStringNotContainsString('UNWANTED', $html);  // not monitored
        $this->assertMatchesRegularExpression('/>\s*50\s*</', $html);
        $this->assertStringContainsString('bg-red-50 text-red-600', $html); // BBB under min

        // Search filters the columns.
        $filtered = $this->actingAs($this->authorizedUser())
            ->get(route('stock-snapshots.index', ['date' => $date, 'q' => 'AAA']), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('AAA', $filtered);
        $this->assertStringNotContainsString('BBB', $filtered);
        $this->assertStringNotContainsString('<html', $filtered);
    }
}
