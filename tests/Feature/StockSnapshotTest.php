<?php

namespace Tests\Feature;

use App\Models\KeseiPart;
use App\Models\Part;
use App\Models\PatternBoard;
use App\Models\PatternGroupItem;
use App\Models\StockSnapshot;
use App\Models\User;
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
