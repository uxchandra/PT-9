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
            ->assertSee('KESEI KANBAN LINE 9')
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

    public function test_closing_table_fills_only_when_a_running_part_reaches_its_closing(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');

        $a = PatternBoard::create(['name' => 'A']);
        $b = PatternBoard::create(['name' => 'B']);
        CalendarEntry::create(['date' => '2026-09-15', 'pattern_board_id' => $a->id]);

        // Running pattern, closing already passed → shows.
        $done = KeseiPart::create(['part_id' => Part::create(['part_no' => 'DONE', 'qty_kbn' => 1])->id, 'closing_time' => '09:00', 'closing_mode' => 'end_of_day', 'urutan' => 1]);
        $done->patternBoards()->sync([$a->id]);
        // Running pattern, closing not reached yet → hidden.
        $waiting = KeseiPart::create(['part_id' => Part::create(['part_no' => 'WAITING'])->id, 'closing_time' => '17:00', 'closing_mode' => 'end_of_day', 'urutan' => 2]);
        $waiting->patternBoards()->sync([$a->id]);
        // Different pattern → hidden.
        $offRun = KeseiPart::create(['part_id' => Part::create(['part_no' => 'OFFRUN'])->id, 'closing_time' => '09:00', 'closing_mode' => 'end_of_day', 'urutan' => 3]);
        $offRun->patternBoards()->sync([$b->id]);

        StockSnapshot::create(['part_no' => 'DONE', 'stock' => 20, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);
        StockSnapshot::create(['part_no' => 'DONE', 'stock' => 17, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:40')]); // -3

        $html = $this->get(route('andon-kesei.show'))->getContent();

        $closingPos = strpos($html, 'CLOSING TIME');
        $stockPos = strpos($html, 'TIMELINE STOK');
        $table = substr($html, $closingPos, $stockPos - $closingPos);

        $this->assertLessThan(strpos($table, 'No Part'), strpos($table, 'Close'));
        // DONE row: Pattern A, Qty Kbn 3.
        $this->assertMatchesRegularExpression('/DONE.*?>\s*A\s*<\/td>.*?>\s*3\s*<\/td>\s*<\/tr>/s', $table);
        $this->assertStringNotContainsString('WAITING', $table);
        $this->assertStringNotContainsString('OFFRUN', $table);

        // Plain text — no coloured badges or pills.
        $this->assertStringNotContainsString('bg-slate-200', $table);
        $this->assertStringNotContainsString('bg-brand-100', $table);

        Carbon::setTestNow();
    }

    public function test_closing_table_shows_the_current_plans_accumulated_kanban(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');

        $a = PatternBoard::create(['name' => 'A']);
        CalendarEntry::create(['date' => '2026-09-15', 'pattern_board_id' => $a->id]);

        $planned = Part::create(['part_no' => 'PLANNED', 'qty_kbn' => 1]);
        $noClose = Part::create(['part_no' => 'NOCLOSE', 'qty_kbn' => 1]);
        // pre_run (default): the plan for the 09-15 07:00 run was fixed at 09-15 03:00.
        KeseiPart::create(['part_id' => $planned->id, 'closing_time' => '03:00', 'urutan' => 1])->patternBoards()->sync([$a->id]);
        KeseiPart::create(['part_id' => $noClose->id, 'urutan' => 2])->patternBoards()->sync([$a->id]);

        // A 4-pc drop lands before the 03:00 cutoff; a later drop is the next plan.
        StockSnapshot::create(['part_no' => 'PLANNED', 'stock' => 20, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 01:00')]);
        StockSnapshot::create(['part_no' => 'PLANNED', 'stock' => 16, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 02:30')]); // -4
        StockSnapshot::create(['part_no' => 'PLANNED', 'stock' => 9, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 09:30')]);

        $html = $this->get(route('andon-kesei.show'))->getContent();
        $table = substr($html, strpos($html, 'CLOSING TIME'), strpos($html, 'TIMELINE STOK') - strpos($html, 'CLOSING TIME'));

        $this->assertMatchesRegularExpression('/PLANNED.*?>\s*4\s*<\/td>\s*<\/tr>/s', $table);
        // No closing time → never enters the closing table.
        $this->assertStringNotContainsString('NOCLOSE', $table);

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

        $a = PatternBoard::create(['name' => 'A']);
        CalendarEntry::create(['date' => '2026-09-15', 'pattern_board_id' => $a->id]);
        $part = Part::create(['part_no' => 'P1', 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $part->id, 'closing_time' => '09:00', 'closing_mode' => 'end_of_day', 'urutan' => 1])->patternBoards()->sync([$a->id]);

        StockSnapshot::create(['part_no' => 'P1', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 07:00')]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 96, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);  // -4, before closing
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 93, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:30')]);  // -3, before closing
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 88, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 10:00')]);  // -5, after closing

        $html = $this->get(route('andon-kesei.show'))->getContent();

        // The green closing marker is drawn.
        $this->assertStringContainsString('closing-time-marker', $html);
        // Drops before 09:00 are folded away — the green number now counts only
        // the live pile (the 5-kanban drop after 09:00).
        $this->assertMatchesRegularExpression('/text-green-700">5</', $html);
        $this->assertStringNotContainsString('stok turun 4 kanban', $html);
        $this->assertStringNotContainsString('stok turun 3 kanban', $html);
        $this->assertStringContainsString('stok turun 5 kanban (5 pcs)', $html);
        // The folded 4 + 3 = 7 lands in the Closing Time table.
        $table = substr($html, strpos($html, 'CLOSING TIME'), strpos($html, 'TIMELINE STOK') - strpos($html, 'CLOSING TIME'));
        $this->assertMatchesRegularExpression('/P1.*?>\s*7\s*<\/td>\s*<\/tr>/s', $table);

        Carbon::setTestNow();
    }

    public function test_red_ticks_pile_across_non_run_days_and_are_never_lost_before_closing(): void
    {
        // Tuesday 06:00 — before today's 08:00 closing. The part only runs on
        // board D days: it ran on 09-11, then not again until 09-15.
        Carbon::setTestNow('2026-09-15 06:00:00');

        $d = PatternBoard::create(['name' => 'D']);
        $x = PatternBoard::create(['name' => 'X']);
        CalendarEntry::create(['date' => '2026-09-11', 'pattern_board_id' => $d->id]);
        CalendarEntry::create(['date' => '2026-09-12', 'pattern_board_id' => $x->id]);
        CalendarEntry::create(['date' => '2026-09-13', 'pattern_board_id' => $x->id]);
        CalendarEntry::create(['date' => '2026-09-14', 'pattern_board_id' => $x->id]);
        CalendarEntry::create(['date' => '2026-09-15', 'pattern_board_id' => $d->id]);

        $part = Part::create(['part_no' => 'EVERY4', 'qty_kbn' => 1]);
        $kesei = KeseiPart::create(['part_id' => $part->id, 'closing_time' => '08:00', 'closing_mode' => 'end_of_day', 'urutan' => 1]);
        $kesei->patternBoards()->sync([$d->id]);

        // Pile starts after the 09-11 08:00 run-day closing, then drops on three
        // separate days — two of them non-run-days.
        StockSnapshot::create(['part_no' => 'EVERY4', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-11 09:00')]);
        StockSnapshot::create(['part_no' => 'EVERY4', 'stock' => 96, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-12 09:00')]);  // -4
        StockSnapshot::create(['part_no' => 'EVERY4', 'stock' => 90, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-14 09:00')]);  // -6
        StockSnapshot::create(['part_no' => 'EVERY4', 'stock' => 87, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 05:00')]);  // -3

        $html = $this->get(route('andon-kesei.show'))->getContent();

        // Every drop since the last run-day closing is still on the board.
        $this->assertStringContainsString('stok turun 4 kanban (4 pcs)', $html);
        $this->assertStringContainsString('stok turun 6 kanban (6 pcs)', $html);
        $this->assertStringContainsString('stok turun 3 kanban (3 pcs)', $html);

        // Pattern X is running right now, not D → EVERY4 is not in the closing table.
        $table = substr($html, strpos($html, 'CLOSING TIME'), strpos($html, 'TIMELINE STOK') - strpos($html, 'CLOSING TIME'));
        $this->assertStringNotContainsString('EVERY4', $table);

        // 06:00 maps to minute 1380 on the looping 07:00 → 07:00 face.
        $this->assertStringContainsString('data-now="1380"', $html);

        Carbon::setTestNow();
    }

    public function test_pile_folds_only_on_a_run_day_closing_and_can_span_several_days(): void
    {
        // Tuesday 10:00 — past today's 08:00 run-day closing.
        Carbon::setTestNow('2026-09-15 10:00:00');

        $d = PatternBoard::create(['name' => 'D']);
        $x = PatternBoard::create(['name' => 'X']);
        CalendarEntry::create(['date' => '2026-09-11', 'pattern_board_id' => $d->id]);
        CalendarEntry::create(['date' => '2026-09-12', 'pattern_board_id' => $x->id]);
        CalendarEntry::create(['date' => '2026-09-13', 'pattern_board_id' => $x->id]);
        CalendarEntry::create(['date' => '2026-09-14', 'pattern_board_id' => $x->id]);
        CalendarEntry::create(['date' => '2026-09-15', 'pattern_board_id' => $d->id]);

        $part = Part::create(['part_no' => 'EVERY4', 'qty_kbn' => 1]);
        $kesei = KeseiPart::create(['part_id' => $part->id, 'closing_time' => '08:00', 'closing_mode' => 'end_of_day', 'urutan' => 1]);
        $kesei->patternBoards()->sync([$d->id]);

        StockSnapshot::create(['part_no' => 'EVERY4', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-11 09:00')]);
        StockSnapshot::create(['part_no' => 'EVERY4', 'stock' => 96, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-12 09:00')]);   // -4, folds
        StockSnapshot::create(['part_no' => 'EVERY4', 'stock' => 90, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-14 09:00')]);   // -6, folds
        StockSnapshot::create(['part_no' => 'EVERY4', 'stock' => 88, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 09:00')]);   // -2, after 08:00 → stays a tick

        $html = $this->get(route('andon-kesei.show'))->getContent();

        // 4 + 6 folded away — green line now counts only the live pile (the 2).
        $this->assertMatchesRegularExpression('/text-green-700">2</', $html);
        $this->assertStringNotContainsString('stok turun 4 kanban', $html);
        $this->assertStringNotContainsString('stok turun 6 kanban', $html);
        $this->assertStringContainsString('stok turun 2 kanban (2 pcs)', $html);
        // The folded 4 + 6 = 10 lands in the Closing Time table.
        $table = substr($html, strpos($html, 'CLOSING TIME'), strpos($html, 'TIMELINE STOK') - strpos($html, 'CLOSING TIME'));
        $this->assertMatchesRegularExpression('/EVERY4.*?>\s*10\s*<\/td>\s*<\/tr>/s', $table);

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
