<?php

namespace Tests\Feature;

use App\Models\CalendarEntry;
use App\Models\KeseiPart;
use App\Models\Part;
use App\Models\PatternBoard;
use App\Models\StockSnapshot;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class KeseiTest extends TestCase
{
    use RefreshDatabase;

    private function authorizedUser(): User
    {
        (new RolePermissionSeeder)->run();

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /** Same rule as AndonKeseiController::window() — sliding 24h, starting 20h
     *  before now (floored to the hour). */
    private function windowStart(): Carbon
    {
        return now()->subHours(20)->startOfHour();
    }

    public function test_kesei_menu_requires_the_manage_kesei_permission(): void
    {
        $this->get(route('kesei.index'))->assertRedirect(route('login'));

        (new RolePermissionSeeder)->run();
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $this->actingAs($staff)->get(route('kesei.index'))->assertForbidden();

        $this->actingAs($this->authorizedUser())->get(route('kesei.index'))->assertOk();
    }

    public function test_a_part_can_be_added_to_kesei(): void
    {
        $part = Part::create(['part_no' => 'P1']);

        $this->actingAs($this->authorizedUser())
            ->post(route('kesei.store'), ['part_id' => $part->id])
            ->assertRedirect(route('kesei.index'));

        $this->assertDatabaseHas('kesei_parts', ['part_id' => $part->id, 'urutan' => 1]);
    }

    public function test_the_same_part_cannot_be_added_twice(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        KeseiPart::create(['part_id' => $part->id, 'urutan' => 1]);

        $this->actingAs($this->authorizedUser())
            ->post(route('kesei.store'), ['part_id' => $part->id])
            ->assertSessionHasErrors('part_id');

        $this->assertSame(1, KeseiPart::count());
    }

    public function test_a_kesei_part_can_be_removed(): void
    {
        $entry = KeseiPart::create(['part_id' => Part::create(['part_no' => 'P1'])->id, 'urutan' => 1]);

        $this->actingAs($this->authorizedUser())
            ->delete(route('kesei.destroy', $entry))
            ->assertRedirect(route('kesei.index'));

        $this->assertSame(0, KeseiPart::count());
    }

    public function test_kesei_parts_can_be_reordered(): void
    {
        $items = collect(['P1', 'P2', 'P3'])->map(fn ($no, $i) => KeseiPart::create([
            'part_id' => Part::create(['part_no' => $no])->id, 'urutan' => $i + 1,
        ]));

        $this->actingAs($this->authorizedUser())
            ->postJson(route('kesei.reorder'), ['order' => [$items[2]->id, $items[0]->id, $items[1]->id]])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, $items[2]->fresh()->urutan);
        $this->assertSame(2, $items[0]->fresh()->urutan);
        $this->assertSame(3, $items[1]->fresh()->urutan);
    }

    public function test_andon_kesei_is_public_and_lists_the_kesei_parts(): void
    {
        KeseiPart::create(['part_id' => Part::create(['part_no' => 'KESEI-PART-A'])->id, 'urutan' => 1]);

        $this->get(route('andon-kesei.show'))
            ->assertOk()
            ->assertSee('ANDON KESEI PT 9')
            ->assertSee('KESEI-PART-A');
    }

    public function test_andon_kesei_shows_stock_decrease_ticks_and_the_stock_table(): void
    {
        $part = Part::create(['part_no' => 'P1', 'qty_kbn' => 1]); // 1 pc = 1 kanban
        KeseiPart::create(['part_id' => $part->id, 'urutan' => 1]);

        $windowStart = $this->windowStart();
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 50, 'std_min' => 10, 'captured_at' => $windowStart]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 47, 'std_min' => 10, 'captured_at' => $windowStart->copy()->addMinutes(15)]);

        $html = $this->get(route('andon-kesei.show'))->getContent();

        // 50 -> 47 = 3 pcs = 3 kanban.
        $this->assertStringContainsString('stok turun 3 kanban (3 pcs)', $html);

        // The Timeline Stok table carries the reading.
        $stockPanelPos = strpos($html, 'TIMELINE STOK');
        $this->assertNotFalse($stockPanelPos);
        $this->assertMatchesRegularExpression('/>\s*50\s*</', substr($html, $stockPanelPos));
    }

    public function test_andon_kesei_ajax_returns_json_partials(): void
    {
        KeseiPart::create(['part_id' => Part::create(['part_no' => 'P1'])->id, 'urutan' => 1]);

        $this->get(route('andon-kesei.show'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJsonStructure(['timeline', 'closingTable', 'stockTimeline', 'serverTime']);
    }

    public function test_andon_kesei_closing_time_table_is_plain_and_shows_the_calendar_pattern(): void
    {
        $running = PatternBoard::create(['name' => 'RUN-A']);
        $other = PatternBoard::create(['name' => 'OTHER-B']);
        CalendarEntry::create(['date' => now()->toDateString(), 'pattern_board_id' => $running->id]);

        // The Kesei row is attached to OTHER-B, but the table shows the pattern
        // the Calendar says is running for the production day.
        $entry = KeseiPart::create(['part_id' => Part::create(['part_no' => 'KP-1'])->id, 'closing_time' => '15:45', 'urutan' => 1]);
        $entry->patternBoards()->sync([$other->id]);

        $html = $this->get(route('andon-kesei.show'))->getContent();

        $closingPos = strpos($html, 'CLOSING TIME');
        $stockPos = strpos($html, 'TIMELINE STOK');
        $this->assertLessThan($stockPos, $closingPos);

        $table = substr($html, $closingPos, $stockPos - $closingPos);
        $this->assertLessThan(strpos($table, 'No Part'), strpos($table, 'Close'));
        $this->assertStringContainsString('KP-1', $table);
        $this->assertStringContainsString('15:45', $table);
        $this->assertStringContainsString('RUN-A', $table);
        $this->assertStringNotContainsString('OTHER-B', $table);

        // Plain text — no coloured badges or pills.
        $this->assertStringNotContainsString('bg-slate-200', $table);
        $this->assertStringNotContainsString('bg-brand-100', $table);
    }

    public function test_closing_table_qty_kbn_is_blank_until_closing_time_then_shows_accumulated_kanban(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00'); // window: 2026-09-14 14:00 → 2026-09-15 14:00

        $reached = Part::create(['part_no' => 'REACHED', 'qty_kbn' => 1]);
        $future = Part::create(['part_no' => 'FUTURE', 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $reached->id, 'closing_time' => '09:00', 'urutan' => 1]); // 09-15 09:00 — passed
        KeseiPart::create(['part_id' => $future->id, 'closing_time' => '13:00', 'urutan' => 2]);  // 09-15 13:00 — not yet

        // Stock drops before REACHED's 09:00 closing.
        StockSnapshot::create(['part_no' => 'REACHED', 'stock' => 20, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);
        StockSnapshot::create(['part_no' => 'REACHED', 'stock' => 16, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:30')]); // -4

        $html = $this->get(route('andon-kesei.show'))->getContent();
        $table = substr($html, strpos($html, 'CLOSING TIME'), strpos($html, 'TIMELINE STOK') - strpos($html, 'CLOSING TIME'));

        // REACHED row's Qty Kbn cell (last <td> of the row) = 4.
        $this->assertMatchesRegularExpression('/REACHED.*?>\s*4\s*<\/td>\s*<\/tr>/s', $table);
        // FUTURE row's closing time not reached — Qty Kbn cell shows "-".
        $this->assertMatchesRegularExpression('/FUTURE.*?>\s*-\s*<\/td>\s*<\/tr>/s', $table);

        Carbon::setTestNow();
    }

    public function test_andon_kesei_timeline_has_a_moving_now_line(): void
    {
        KeseiPart::create(['part_id' => Part::create(['part_no' => 'P1'])->id, 'urutan' => 1]);

        $html = $this->get(route('andon-kesei.show'))->getContent();

        $this->assertMatchesRegularExpression('/id="kesei-now-line"[^>]*data-now="\d+/', $html);
        $this->assertStringContainsString('__keseiNowSync', $html);
    }

    public function test_import_adds_parts_creates_missing_ones_and_skips_duplicates(): void
    {
        Part::create(['part_no' => 'EXISTING-1']);
        $already = Part::create(['part_no' => 'ALREADY-IN-KESEI']);
        KeseiPart::create(['part_id' => $already->id, 'urutan' => 1]);

        $csv = implode("\n", [
            'part_no',
            'EXISTING-1',
            'NEW-PART',
            'ALREADY-IN-KESEI',   // already in Kesei -> skip
            'NEW-PART',           // repeated in file -> skip the 2nd
            '',                   // blank -> skip
        ]);

        $this->actingAs($this->authorizedUser())
            ->post(route('kesei.import.store'), ['file' => $this->csvUpload($csv)])
            ->assertRedirect(route('kesei.index'));

        // ALREADY-IN-KESEI + EXISTING-1 + NEW-PART = 3, no dupes.
        $this->assertSame(3, KeseiPart::count());
        $this->assertSame(1, KeseiPart::whereHas('part', fn ($q) => $q->where('part_no', 'NEW-PART'))->count());
        $this->assertNotNull(Part::where('part_no', 'NEW-PART')->first(), 'missing part created in Part List');

        // Re-import the whole file again: nothing new, no dupes.
        $this->actingAs($this->authorizedUser())
            ->post(route('kesei.import.store'), ['file' => $this->csvUpload($csv)]);

        $this->assertSame(3, KeseiPart::count());
    }

    public function test_stock_source_makes_andon_kesei_sum_several_source_parts(): void
    {
        // One Kesei row (labelled by FAMILY-A) whose Timeline Stok is the sum
        // of two other parts' Stock Part All readings.
        $family = Part::create(['part_no' => 'FAMILY-A', 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $family->id, 'stock_source' => 'SRC-1, SRC-2', 'urutan' => 1]);

        $windowStart = $this->windowStart();
        // t0: SRC-1 = 30, SRC-2 = 20  -> sum 50
        StockSnapshot::create(['part_no' => 'SRC-1', 'stock' => 30, 'std_min' => 5, 'captured_at' => $windowStart]);
        StockSnapshot::create(['part_no' => 'SRC-2', 'stock' => 20, 'std_min' => 5, 'captured_at' => $windowStart]);
        // t1: SRC-1 = 26, SRC-2 = 20  -> sum 46 (dropped 4)
        StockSnapshot::create(['part_no' => 'SRC-1', 'stock' => 26, 'std_min' => 5, 'captured_at' => $windowStart->copy()->addMinutes(15)]);
        StockSnapshot::create(['part_no' => 'SRC-2', 'stock' => 20, 'std_min' => 5, 'captured_at' => $windowStart->copy()->addMinutes(15)]);

        $html = $this->get(route('andon-kesei.show'))->getContent();

        // Timeline Stok shows the summed value, not either source alone.
        $stockPos = strpos($html, 'TIMELINE STOK');
        $this->assertMatchesRegularExpression('/>\s*50\s*</', substr($html, $stockPos));
        $this->assertMatchesRegularExpression('/>\s*46\s*</', substr($html, $stockPos));

        // The decrease tick reflects the summed drop (50 -> 46 = 4).
        $this->assertStringContainsString('stok turun 4 kanban (4 pcs)', $html);
        // Source part numbers are not shown as their own rows.
        $this->assertStringNotContainsString('>SRC-1<', $html);
    }

    public function test_stock_source_can_be_set_inline_via_patch(): void
    {
        $entry = KeseiPart::create(['part_id' => Part::create(['part_no' => 'P1'])->id, 'urutan' => 1]);

        $this->actingAs($this->authorizedUser())
            ->patchJson(route('kesei.update', $entry), ['stock_source' => ' A ,B, A , '])
            ->assertOk()
            ->assertJson(['ok' => true, 'stock_source' => 'A, B']); // trimmed + de-duped

        $this->assertSame('A, B', $entry->fresh()->stock_source);

        // Clearing it falls back to null (own part_no).
        $this->actingAs($this->authorizedUser())
            ->patchJson(route('kesei.update', $entry), ['stock_source' => ''])
            ->assertOk();
        $this->assertNull($entry->fresh()->stock_source);
    }

    public function test_import_sets_and_updates_stock_source(): void
    {
        $existing = Part::create(['part_no' => 'EXISTING']);
        $entry = KeseiPart::create(['part_id' => $existing->id, 'urutan' => 1]);

        $csv = implode("\n", [
            'part_no,stock_source',
            'NEW-ONE,"SRC-A, SRC-B"',   // new row with a source
            'EXISTING,"SRC-C"',          // already in Kesei -> update source only
        ]);

        $this->actingAs($this->authorizedUser())
            ->post(route('kesei.import.store'), ['file' => $this->csvUpload($csv)])
            ->assertRedirect(route('kesei.index'));

        $this->assertSame(2, KeseiPart::count());
        $this->assertSame('SRC-A, SRC-B', KeseiPart::whereHas('part', fn ($q) => $q->where('part_no', 'NEW-ONE'))->first()->stock_source);
        $this->assertSame('SRC-C', $entry->fresh()->stock_source);
    }

    public function test_a_kesei_row_can_carry_a_closing_time_and_several_pattern_boards(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        $a = PatternBoard::create(['name' => 'A']);
        $b = PatternBoard::create(['name' => 'B']);

        $this->actingAs($this->authorizedUser())
            ->post(route('kesei.store'), [
                'part_id' => $part->id,
                'closing_time' => '14:30',
                'pattern_board_ids' => [$a->id, $b->id],
            ])
            ->assertRedirect(route('kesei.index'));

        $entry = KeseiPart::first();
        $this->assertSame('14:30', $entry->closing_time?->format('H:i'));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $entry->patternBoards->pluck('id')->all());
    }

    public function test_closing_time_and_patterns_can_be_set_inline_via_patch(): void
    {
        $a = PatternBoard::create(['name' => 'A']);
        $b = PatternBoard::create(['name' => 'B']);
        $entry = KeseiPart::create(['part_id' => Part::create(['part_no' => 'P1'])->id, 'urutan' => 1]);
        $user = $this->authorizedUser();

        $this->actingAs($user)
            ->patchJson(route('kesei.update', $entry), ['closing_time' => '08:15'])
            ->assertOk()->assertJson(['ok' => true, 'closing_time' => '08:15']);
        $this->assertSame('08:15', $entry->fresh()->closing_time?->format('H:i'));

        $this->actingAs($user)
            ->patchJson(route('kesei.update', $entry), ['pattern_board_ids' => [$a->id, $b->id]])
            ->assertOk();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $entry->fresh()->patternBoards->pluck('id')->all());

        // Sending only patterns must not wipe the closing time.
        $this->assertSame('08:15', $entry->fresh()->closing_time?->format('H:i'));

        // Sending an empty array clears the boards.
        $this->actingAs($user)->patchJson(route('kesei.update', $entry), ['pattern_board_ids' => []])->assertOk();
        $this->assertCount(0, $entry->fresh()->patternBoards);
    }

    public function test_andon_kesei_draws_a_closing_marker_and_folds_earlier_ticks_into_an_accumulated_number(): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00'); // window 09-14 16:00 → 09-15 16:00; closing 09:00 = 09-15 09:00 (passed)

        $part = Part::create(['part_no' => 'P1', 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $part->id, 'closing_time' => '09:00', 'urutan' => 1]);

        StockSnapshot::create(['part_no' => 'P1', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 07:00')]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 96, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);  // -4, before closing
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 93, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:30')]);  // -3, before closing
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 88, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 10:00')]);  // -5, after closing

        $html = $this->get(route('andon-kesei.show'))->getContent();

        // The green closing marker is drawn.
        $this->assertStringContainsString('closing-time-marker', $html);
        // Drops before 09:00 (4 + 3 = 7) are folded into the accumulated number.
        $this->assertMatchesRegularExpression('/text-green-700">7</', $html);
        $this->assertStringNotContainsString('stok turun 4 kanban', $html);
        $this->assertStringNotContainsString('stok turun 3 kanban', $html);
        // The drop after 09:00 still shows its ticks.
        $this->assertStringContainsString('stok turun 5 kanban (5 pcs)', $html);

        Carbon::setTestNow();
    }

    public function test_import_sets_closing_time_and_resolves_multiple_patterns_by_name(): void
    {
        $a = PatternBoard::create(['name' => 'A']);
        $b = PatternBoard::create(['name' => 'B']);

        $csv = implode("\n", [
            'part_no,stock_source,closing_time,pattern',
            'P1,,14:30,"A, B"',
            'P2,,,"A, ZZZ"',   // ZZZ unknown -> only A attached
        ]);

        $this->actingAs($this->authorizedUser())
            ->post(route('kesei.import.store'), ['file' => $this->csvUpload($csv)])
            ->assertRedirect(route('kesei.index'));

        $p1 = KeseiPart::whereHas('part', fn ($q) => $q->where('part_no', 'P1'))->first();
        $this->assertSame('14:30', $p1->closing_time?->format('H:i'));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $p1->patternBoards->pluck('id')->all());

        $p2 = KeseiPart::whereHas('part', fn ($q) => $q->where('part_no', 'P2'))->first();
        $this->assertSame([$a->id], $p2->patternBoards->pluck('id')->all());
    }

    public function test_import_template_downloads_an_xlsx(): void
    {
        $this->actingAs($this->authorizedUser())
            ->get(route('kesei.import.template'))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=template-import-kesei.xlsx');
    }

    public function test_import_requires_the_manage_kesei_permission(): void
    {
        (new RolePermissionSeeder)->run();
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff)->get(route('kesei.import.create'))->assertForbidden();
    }

    private function csvUpload(string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'kesei').'.csv';
        file_put_contents($path, rtrim($contents, "\n")."\n");

        return new UploadedFile($path, 'kesei.csv', 'text/csv', null, true);
    }
}
