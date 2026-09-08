<?php

namespace Tests\Feature;

use App\Models\CalendarEntry;
use App\Models\Machine;
use App\Models\Part;
use App\Models\Pattern;
use App\Models\PatternActual;
use App\Models\PatternBoard;
use App\Models\PatternGroupItem;
use App\Models\Rest;
use App\Models\StockSnapshot;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AndonTest extends TestCase
{
    use RefreshDatabase;

    private function authorizedUser(): User
    {
        (new RolePermissionSeeder)->run();

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_andon_index_is_public_and_lists_boards(): void
    {
        PatternBoard::create(['name' => 'Board X']);

        $response = $this->get('/andon');

        $response->assertOk();
        $response->assertSee('Board X');
    }

    public function test_andon_show_never_splits_loading_or_dandori_around_a_regular_rest(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $partA = Part::create(['part_no' => 'P1']);

        // dandori (10min) 07:00-07:10, loading_time (100min) 07:10-08:50 — the
        // 08:00-08:30 Lunch rest falls entirely inside that window but must NOT
        // pause/split it: only the shift-change gap does that now.
        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $partA->id,
            'urutan' => 1,
            'loading_time' => 100,
            'jumlah_proses' => 2,
            'total_kanban' => 5,
            'dandori' => 10,
        ]);

        Rest::create([
            'name' => 'Lunch',
            'start_time' => '08:00',
            'end_time' => '08:30',
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $partA->id,
            'proses' => 1,
        ]);

        $response = $this->get("/andon/{$board->id}");
        $response->assertOk();

        $html = $response->getContent();

        $response->assertSee('M1');
        // The rest band still renders (as a reference marker, behind the bar) even
        // though it no longer affects scheduling.
        $response->assertSee('Lunch');
        $response->assertSee('P1 1/2');

        // Scoped to the Pattern card only — the same label also legitimately
        // appears again in the Planning card further down the page.
        $patternCardStart = strpos($html, 'id="andon-panel-timeline"');
        $patternCardEnd = strpos($html, '<!-- Card: Kesei -->');
        $patternCardHtml = substr($html, $patternCardStart, $patternCardEnd - $patternCardStart);

        // One continuous segment (div): title attribute + 1 visible span = 2 occurrences.
        // If this were split around the rest it would be 3+ (regression guard).
        $this->assertSame(2, substr_count($patternCardHtml, 'P1 1/2'));

        // Free time blocks are hidden for now.
        $response->assertDontSee('FREE TIME');
    }

    public function test_andon_show_orders_machine_rows_naturally_by_name(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);

        // Created out of order, and each assigned to a part whose "urutan" would
        // group them in this same wrong order (99, 91, 92) if rows still followed
        // part order instead of being sorted by machine name afterwards.
        $pt99 = Machine::create(['name' => 'PT99']);
        $pt91 = Machine::create(['name' => 'PT91']);
        $pt92 = Machine::create(['name' => 'PT92']);

        $partA = Part::create(['part_no' => 'A']);
        $partB = Part::create(['part_no' => 'B']);
        $partC = Part::create(['part_no' => 'C']);

        foreach ([$partA, $partB, $partC] as $i => $part) {
            PatternGroupItem::create([
                'pattern_board_id' => $board->id,
                'part_id' => $part->id,
                'urutan' => $i + 1,
                'loading_time' => 10,
                'jumlah_proses' => 1,
                'total_kanban' => 1,
                'dandori' => 0,
            ]);
        }

        Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $pt99->id, 'part_id' => $partA->id, 'proses' => 1]);
        Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $pt91->id, 'part_id' => $partB->id, 'proses' => 1]);
        Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $pt92->id, 'part_id' => $partC->id, 'proses' => 1]);

        $html = $this->get("/andon/{$board->id}")->getContent();

        $posPt91 = strpos($html, 'PT91');
        $posPt92 = strpos($html, 'PT92');
        $posPt99 = strpos($html, 'PT99');

        $this->assertNotFalse($posPt91);
        $this->assertNotFalse($posPt92);
        $this->assertNotFalse($posPt99);
        $this->assertTrue($posPt91 < $posPt92 && $posPt92 < $posPt99, 'machine rows must render in natural name order PT91, PT92, PT99');
    }

    public function test_andon_show_orders_each_machines_blocks_by_kelompok_pattern_urutan(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);

        $partHigh = Part::create(['part_no' => 'PartHigh']); // urutan=1
        $partLow = Part::create(['part_no' => 'PartLow']);   // urutan=2

        PatternGroupItem::create([
            'pattern_board_id' => $board->id, 'part_id' => $partHigh->id,
            'urutan' => 1, 'loading_time' => 10, 'jumlah_proses' => 1, 'total_kanban' => 1, 'dandori' => 0,
        ]);
        PatternGroupItem::create([
            'pattern_board_id' => $board->id, 'part_id' => $partLow->id,
            'urutan' => 2, 'loading_time' => 10, 'jumlah_proses' => 1, 'total_kanban' => 1, 'dandori' => 0,
        ]);

        // Assignment for the urutan=2 part is created FIRST — the board must
        // still schedule the urutan=1 part first (drag-to-reorder drives this).
        Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $machine->id, 'part_id' => $partLow->id, 'proses' => 1]);
        Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $machine->id, 'part_id' => $partHigh->id, 'proses' => 1]);

        $html = $this->get("/andon/{$board->id}")->getContent();

        $posLow = strpos($html, 'PartLow');
        $posHigh = strpos($html, 'PartHigh');

        $this->assertNotFalse($posLow);
        $this->assertNotFalse($posHigh);
        $this->assertTrue($posHigh < $posLow, 'blocks must follow Kelompok Pattern urutan, not assignment creation order');
    }

    public function test_andon_show_renders_the_shift_change_gap_between_shift_1_and_shift_2(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $part = Part::create(['part_no' => 'P1']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 30,
            'jumlah_proses' => 1,
            'total_kanban' => 5,
            'dandori' => 0,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        $response = $this->get("/andon/{$board->id}");

        $response->assertOk();
        $response->assertSee('Pergantian Shift');
        // 06:00 the next day (tail end of shift 2) must be on the axis.
        $response->assertSee('06:00');
    }

    public function test_andon_show_schedules_shift_2_assignments_starting_at_20_00_instead_of_leaving_it_empty(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $partShift1 = Part::create(['part_no' => 'SHIFT1-PART']);
        $partShift2 = Part::create(['part_no' => 'SHIFT2-PART']);

        // Shift 1's own item — a short job that finishes long before 16:00,
        // the way the old single-cursor scheduling used to leave everything
        // after it (including all of shift 2) marked as free time.
        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $partShift1->id,
            'shift' => 1,
            'urutan' => 1,
            'loading_time' => 30,
            'jumlah_proses' => 1,
            'total_kanban' => 5,
            'dandori' => 0,
        ]);

        // A separate item explicitly for shift 2.
        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $partShift2->id,
            'shift' => 2,
            'urutan' => 2,
            'loading_time' => 45,
            'jumlah_proses' => 1,
            'total_kanban' => 8,
            'dandori' => 0,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $partShift1->id,
            'shift' => 1,
            'proses' => 1,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $partShift2->id,
            'shift' => 2,
            'proses' => 1,
        ]);

        $html = $this->get("/andon/{$board->id}")->getContent();

        // Both shifts' blocks must render — shift 2 is no longer stuck empty
        // just because shift 1 finished its own workload early.
        $this->assertStringContainsString('SHIFT1-PART 1/1', $html);
        $this->assertStringContainsString('SHIFT2-PART 1/1', $html);

        // The shift 2 block must start at 20:00 (minute 1200 since day start
        // 07:00 = minute 420), not right after shift 1's block ends at 07:30.
        $pxPerMinute = 1.8;
        $expectedLeft = (1200 - 420) * $pxPerMinute;
        $this->assertStringContainsString('left: '.$expectedLeft.'px', $html);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function currentStockWindow(): array
    {
        $now = now();
        $cutoff = $now->copy()->setTime(6, 0);
        $windowStart = $now->lt($cutoff) ? $now->copy()->subDay()->setTime(7, 0) : $now->copy()->setTime(7, 0);

        return [$windowStart, $windowStart->copy()->addHours(23)];
    }

    public function test_kosei_timeline_shows_a_tick_per_kanban_when_stock_drops(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $part = Part::create(['part_no' => 'P1', 'qty_kbn' => 1]); // 1 pc = 1 kanban, easy to count

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 10,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        [$windowStart] = $this->currentStockWindow();

        StockSnapshot::create(['part_no' => 'P1', 'stock' => 50, 'std_min' => 10, 'captured_at' => $windowStart]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 48, 'std_min' => 10, 'captured_at' => $windowStart->copy()->addMinutes(15)]);
        // Restock — must not produce a tick.
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 60, 'std_min' => 10, 'captured_at' => $windowStart->copy()->addMinutes(30)]);

        $html = $this->get("/andon/{$board->id}")->getContent();

        $this->assertStringContainsString('stok turun 2 kanban (2 pcs)', $html);
        $this->assertStringNotContainsString('kanban (12 pcs)', $html, 'a stock increase must not produce a decrease tick');

        // 2 red tick bars for the 2-kanban drop, plus the "2" label under them.
        $eventTitlePos = strpos($html, 'stok turun 2 kanban (2 pcs)');
        $this->assertNotFalse($eventTitlePos);
        $snippet = substr($html, $eventTitlePos, 700);
        $this->assertSame(2, substr_count($snippet, 'bg-red-500'));
        $this->assertStringContainsString('>2</span>', $snippet);
    }

    public function test_kosei_timeline_rounds_a_sub_kanban_decrease_up_to_1_tick(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        // A 2pc drop is far smaller than this part's 100pc Qty Kbn, but the
        // same round-up rule as total_kanban still counts it as 1 kanban.
        $part = Part::create(['part_no' => 'P1', 'qty_kbn' => 100]);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 10,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        [$windowStart] = $this->currentStockWindow();

        StockSnapshot::create(['part_no' => 'P1', 'stock' => 50, 'std_min' => 10, 'captured_at' => $windowStart]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 48, 'std_min' => 10, 'captured_at' => $windowStart->copy()->addMinutes(15)]);

        $html = $this->get("/andon/{$board->id}")->getContent();

        $this->assertStringContainsString('stok turun 1 kanban (2 pcs)', $html);
    }

    public function test_timeline_stok_shows_every_part_side_by_side_without_needing_a_click(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $partA = Part::create(['part_no' => 'PART-A']);
        $partB = Part::create(['part_no' => 'PART-B']);

        foreach ([$partA, $partB] as $i => $part) {
            PatternGroupItem::create([
                'pattern_board_id' => $board->id,
                'part_id' => $part->id,
                'urutan' => $i + 1,
                'loading_time' => 10,
                'jumlah_proses' => 1,
                'total_kanban' => 1,
                'dandori' => 0,
            ]);

            Pattern::create([
                'pattern_board_id' => $board->id,
                'machine_id' => $machine->id,
                'part_id' => $part->id,
                'proses' => 1,
            ]);
        }

        [$windowStart] = $this->currentStockWindow();

        // Both parts captured in the same run/timestamp, like the real
        // snapshot command does — PART-B is under its std_min.
        StockSnapshot::create(['part_no' => 'PART-A', 'stock' => 50, 'std_min' => 10, 'captured_at' => $windowStart]);
        StockSnapshot::create(['part_no' => 'PART-B', 'stock' => 5, 'std_min' => 10, 'captured_at' => $windowStart]);

        $html = $this->get("/andon/{$board->id}")->getContent();

        // Both parts appear as columns, with no click/selection needed.
        $this->assertStringContainsString('PART-A', $html);
        $this->assertStringContainsString('PART-B', $html);
        $this->assertStringNotContainsString('Pilih part di Kosei', $html);

        // One shared time row carries both parts' stock values — search only
        // within the Timeline Stok panel, since the same "07:00" text also
        // appears earlier on the Pattern gantt's own time axis.
        $stockPanelPos = strpos($html, 'TIMELINE STOK');
        $this->assertNotFalse($stockPanelPos);
        $timePos = strpos($html, $windowStart->format('H:i'), $stockPanelPos);
        $this->assertNotFalse($timePos);
        $rowSnippet = substr($html, $timePos, 1000);
        $this->assertMatchesRegularExpression('/>\s*50\s*</', $rowSnippet);
        $this->assertMatchesRegularExpression('/>\s*5\s*</', $rowSnippet);
        // PART-B's under-min cell is highlighted.
        $this->assertStringContainsString('bg-red-50 text-red-600', $rowSnippet);
    }

    public function test_andon_show_returns_json_partials_for_ajax_refresh_instead_of_the_full_page(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $part = Part::create(['part_no' => 'P1']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 10,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        $response = $this->get("/andon/{$board->id}", ['X-Requested-With' => 'XMLHttpRequest']);

        $response->assertOk();
        $response->assertJsonStructure(['timeline', 'kosei', 'stockTimeline', 'planning', 'nowMinute', 'serverTime', 'boardId', 'boardName']);

        // The panel fragments must not include the full page shell (no
        // duplicate <html>/board switcher) — just the fragment markup itself.
        $data = $response->json();
        $this->assertStringContainsString('P1', $data['timeline']);
        $this->assertStringContainsString('P1', $data['kosei']);
        $this->assertStringContainsString('P1', $data['planning']);
        $this->assertStringNotContainsString('<html', $data['timeline']);
        $this->assertIsInt($data['nowMinute']);
    }

    public function test_andon_show_page_has_all_3_cards_with_planning_collapsed_by_default(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        // qty_kbn = 1 so pcs decreased === kanban, easy to reason about.
        $part = Part::create(['part_no' => 'P1', 'qty_kbn' => 1]);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 30,
            'jumlah_proses' => 1,
            'total_kanban' => 999, // Pattern card's own number — must stay 999 there
            'dandori' => 0,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        [$windowStart] = $this->currentStockWindow();
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 100, 'std_min' => 0, 'captured_at' => $windowStart->copy()->subHours(5)]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 88, 'std_min' => 0, 'captured_at' => $windowStart->copy()->subHours(4)]);

        $html = $this->get("/andon/{$board->id}")->getContent();

        // All 3 cards exist on the one page.
        $response = $this->get("/andon/{$board->id}");
        $response->assertSee('PATTERN', false);
        $response->assertSee('KESEI', false);
        $response->assertSee('PLANNING', false);

        // Planning starts collapsed (like the current default view), the
        // other two start expanded — this is the fallback andonPanels() uses
        // the first time a browser has no saved arrangement yet.
        $this->assertStringContainsString(
            "safeStorage('andon-card-collapsed', { andon: false, kosei: false, planning: true })", $html
        );

        // The Pattern card keeps its manually-configured total_kanban (999),
        // while the Planning card further down shows the Kesei-derived value
        // (12 pcs decreased ÷ qty_kbn 1 = 12) for the exact same part+slot —
        // proving the two cards render independently from one dataset.
        $patternCardStart = strpos($html, 'id="andon-panel-timeline"');
        $planningCardStart = strpos($html, 'id="andon-panel-planning"');
        $patternCardHtml = substr($html, $patternCardStart, $planningCardStart - $patternCardStart);
        $planningCardHtml = substr($html, $planningCardStart);

        $this->assertStringContainsString('Kanban: 999', $patternCardHtml);
        $this->assertStringContainsString('Kanban: 12', $planningCardHtml);
        $this->assertStringNotContainsString('Kanban: 999', $planningCardHtml);
    }

    public function test_andon_planning_shows_the_same_schedule_as_the_pattern_board(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $part = Part::create(['part_no' => 'P1']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 30,
            'jumlah_proses' => 1,
            'total_kanban' => 5,
            'dandori' => 0,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        $response = $this->get("/andon-planning/{$board->id}");

        $response->assertOk();
        $response->assertSee('PLANNING');
        $response->assertSee('M1');
        $response->assertSee('P1 1/1');
    }

    public function test_andon_planning_kanban_comes_from_kesei_demand_at_closing_time_not_total_kanban(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        // qty_kbn = 1 so pcs decreased === kanban, easy to reason about.
        $part = Part::create(['part_no' => 'P1', 'qty_kbn' => 1]);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 60,
            'jumlah_proses' => 1,
            'total_kanban' => 999, // must be ignored on the Planning board
            'dandori' => 30,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        [$windowStart] = $this->currentStockWindow();

        // This part is the first/only one on M1 in shift 1, so its dandori
        // starts right at day-start (07:00) — closing time = 07:00 minus 4h.
        // Two decrease events are seeded: one exactly at the dandori-based
        // closing time (07:00 - 4h), and a decoy 30 min later exactly at
        // what the closing time WOULD be if it were computed from the
        // loading start instead (07:30 - 4h). Only the dandori-based one
        // must be picked.
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 100, 'std_min' => 0, 'captured_at' => $windowStart->copy()->subHours(5)]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 85, 'std_min' => 0, 'captured_at' => $windowStart->copy()->subHours(4)]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 50, 'std_min' => 0, 'captured_at' => $windowStart->copy()->subHours(3)->subMinutes(30)]);

        $html = $this->get("/andon-planning/{$board->id}")->getContent();

        $this->assertStringContainsString('Kanban: 15', $html);
        $this->assertStringNotContainsString('Kanban: 35', $html);
        $this->assertStringNotContainsString('Kanban: 999', $html);
    }

    public function test_andon_planning_plans_0_kanban_when_no_decrease_happened_by_closing_time(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $part = Part::create(['part_no' => 'P1', 'qty_kbn' => 1]);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 30,
            'jumlah_proses' => 1,
            'total_kanban' => 999,
            'dandori' => 0,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        // No StockSnapshot rows at all for this part.
        $html = $this->get("/andon-planning/{$board->id}")->getContent();

        // Not yet "released" from Kesei — the caption is left off entirely
        // rather than showing a misleading "KB 0" / "Kanban: 0".
        $this->assertStringContainsString('P1 1/1', $html);
        $this->assertStringNotContainsString('Kanban: 0', $html);
        $this->assertStringNotContainsString('KB 0', $html);
        $this->assertStringNotContainsString('Kanban: 999', $html);
    }

    public function test_andon_planning_returns_json_partial_for_ajax_refresh(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $part = Part::create(['part_no' => 'P1']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 10,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        $response = $this->get("/andon-planning/{$board->id}", ['X-Requested-With' => 'XMLHttpRequest']);

        $response->assertOk();
        $response->assertJsonStructure(['timeline', 'nowMinute', 'serverTime']);
        $this->assertStringContainsString('P1', $response->json('timeline'));
    }

    public function test_andon_planning_renders_an_actual_kanban_input_at_75_percent_of_the_loading_blocks_width(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $part = Part::create(['part_no' => 'P1']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 60, // 60min * 1.8px/min = 108px wide block
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
        ]);

        $pattern = Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        $html = $this->get("/andon-planning/{$board->id}")->getContent();

        $this->assertStringContainsString('andon-actual-input', $html);
        $this->assertStringContainsString('data-pattern-id="'.$pattern->id.'"', $html);
        // Sized to exactly 75% of the loading block's own width (108px * 0.75
        // = 81px) — the actual row is independently (packed) positioned now,
        // so this is an explicit pixel width rather than a CSS "w-[75%]"
        // relative to a shared parent.
        $this->assertStringContainsString('width: 81px', $html);
        // Square corners like the loading block, not rounded.
        $this->assertDoesNotMatchRegularExpression('/andon-actual-input[^"]*rounded/', $html);
    }

    public function test_andon_planning_actual_input_is_left_aligned_flush_under_loading_and_dandori_stays_split(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $part = Part::create(['part_no' => 'P1']);

        // dandori 10min (07:00-07:10, left 0px width 18px), loading 30min
        // (07:10-07:40, left 18px width 54px) at 1.8px/min.
        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 30,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 10,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        $html = $this->get("/andon-planning/{$board->id}")->getContent();

        // Rencana row (top, 36px tall) sits at the block's real timeline
        // position: dandori 0-18px, loading 18-54px.
        $dandoriRencanaPos = strpos($html, 'left: 0px; width: 18px; top: 6px; height: 36px;');
        $this->assertNotFalse($dandoriRencanaPos, 'dandori Rencana piece must sit at its real timeline position');

        $loadingRencanaPos = strpos($html, 'left: 18px; width: 54px; top: 6px; height: 36px;');
        $this->assertNotFalse($loadingRencanaPos, 'loading Rencana piece must sit at its real timeline position');

        // Aktual row (bottom, top = 6 + 36 = 42px, 22px tall) is packed with
        // no idle gap — for this single-block-pair row it lands at the exact
        // same left as Rencana since there is nothing before it to skip.
        $dandoriAktualPos = strpos($html, 'left: 0px; width: 18px; top: 42px; height: 22px;');
        $this->assertNotFalse($dandoriAktualPos, 'dandori Aktual piece must be packed right after the Rencana row');

        $loadingAktualPos = strpos($html, 'left: 18px; width: 40.5px; top: 42px; height: 22px;');
        $this->assertNotFalse($loadingAktualPos, 'actual-input must be packed flush under its own loading block');

        // dandori is split into a "Rencana" piece and an "Aktual" piece
        // (each its own independently positioned element) instead of one
        // block spanning both.
        $dandoriTitlePos = strpos($html, 'title="Dandori (Rencana): 10 menit"');
        $this->assertNotFalse($dandoriTitlePos);
        $this->assertGreaterThan($dandoriRencanaPos, $dandoriTitlePos);
        $this->assertLessThan($loadingRencanaPos, $dandoriTitlePos);

        $dandoriActualTitlePos = strpos($html, 'title="Dandori (Aktual): 10 menit"');
        $this->assertNotFalse($dandoriActualTitlePos);
        $this->assertGreaterThan($dandoriTitlePos, $dandoriActualTitlePos);

        // The actual-input comes after the loading Rencana piece, as an
        // independently positioned element — not nested inside it.
        $inputPos = strpos($html, 'andon-actual-input');
        $this->assertNotFalse($inputPos);
        $this->assertGreaterThan($loadingRencanaPos, $inputPos);
    }

    public function test_andon_planning_actual_row_has_no_blank_space_left_by_the_narrower_actual_input(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $partA = Part::create(['part_no' => 'PA']);
        $partB = Part::create(['part_no' => 'PB']);

        // Part A: 40min loading, no dandori (07:00-07:40, left 0px width
        // 72px). Its actual-input only draws 75% of that (54px) — the pack
        // cursor must advance by the drawn 54px, not the full 72px, so Part
        // B's dandori touches the input's real right edge with zero blank
        // space, instead of leaving an 18px gap where nothing is drawn.
        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $partA->id,
            'urutan' => 1,
            'loading_time' => 40,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
        ]);
        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $partB->id,
            'urutan' => 2,
            'loading_time' => 20,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 10,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $partA->id,
            'proses' => 1,
        ]);
        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $partB->id,
            'proses' => 1,
        ]);

        $html = $this->get("/andon-planning/{$board->id}")->getContent();

        // Rencana: dandori for Part B sits flush at 72px (0 + 72, real
        // timeline, cursor-continuous).
        $this->assertStringContainsString('left: 72px; width: 18px; top: 6px; height: 36px;', $html);

        // Aktual: Part A's input is drawn 0-54px (75% of 72px). Part B's
        // dandori Aktual piece must touch right there, at 54px — not 72px,
        // which would leave the undrawn 18px blank.
        $this->assertStringContainsString('left: 0px; width: 54px; top: 42px; height: 22px;', $html);
        $this->assertStringContainsString('left: 54px; width: 18px; top: 42px; height: 22px;', $html);
        $this->assertStringNotContainsString('left: 72px; width: 18px; top: 42px; height: 22px;', $html);

        // And Part B's own actual-input touches right after that dandori's
        // Aktual piece, at 54 + 18 = 72px.
        $this->assertStringContainsString('left: 72px; width: 27px; top: 42px; height: 22px;', $html);
    }

    public function test_andon_planning_actual_row_packs_within_a_shift_but_never_drags_shift_2_into_shift_1(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $partA = Part::create(['part_no' => 'PA']);
        $partB = Part::create(['part_no' => 'PB']);

        // Part A: 30min loading in shift 1, starting 07:00 → ends 07:30.
        // Part B: shift 2, starting 20:00 — a real ~12.5h idle stretch (rest
        // of shift 1 + the shift-change gap) separates them.
        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $partA->id,
            'urutan' => 1,
            'loading_time' => 30,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
            'shift' => 1,
        ]);
        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $partB->id,
            'urutan' => 1,
            'loading_time' => 20,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 10,
            'shift' => 2,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $partA->id,
            'proses' => 1,
            'shift' => 1,
        ]);
        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $partB->id,
            'proses' => 1,
            'shift' => 2,
        ]);

        $html = $this->get("/andon-planning/{$board->id}")->getContent();

        // Rencana: Part B's dandori sits at its real (shift 2) position,
        // 20:00 = minute 1200 → (1200-420)*1.8 = 1404px.
        $this->assertStringContainsString('left: 1404px; width: 18px; top: 6px; height: 36px;', $html);

        // Aktual: the idle stretch between shift 1 and shift 2 is real
        // machine downtime, not something to pack away — Part B's dandori
        // Aktual piece must stay anchored at the same 1404px, NOT get
        // dragged back to sit right after Part A's loading (54px).
        $this->assertStringContainsString('left: 1404px; width: 18px; top: 42px; height: 22px;', $html);
        $this->assertStringNotContainsString('left: 54px; width: 18px; top: 42px; height: 22px;', $html);
    }

    public function test_actual_kanban_can_be_saved_and_is_shown_back_on_the_planning_board(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $part = Part::create(['part_no' => 'P1']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 30,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
        ]);

        $pattern = Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        $response = $this->postJson("/andon-planning/pattern/{$pattern->id}/actual", ['actual_kanban' => 17]);

        $response->assertOk();
        $response->assertJson(['ok' => true, 'actual_kanban' => 17]);

        [$windowStart] = $this->currentStockWindow();
        $saved = PatternActual::where('pattern_id', $pattern->id)->first();
        $this->assertNotNull($saved);
        $this->assertSame($windowStart->toDateString(), $saved->produced_on);
        $this->assertSame(17, $saved->actual_kanban);

        $html = $this->get("/andon-planning/{$board->id}")->getContent();
        $this->assertStringContainsString('value="17"', $html);

        // Saving again (e.g. correcting the value) updates the same row
        // instead of creating a second one for the same day.
        $this->postJson("/andon-planning/pattern/{$pattern->id}/actual", ['actual_kanban' => 20])->assertOk();
        $this->assertSame(1, PatternActual::where('pattern_id', $pattern->id)->count());
        $this->assertDatabaseHas('pattern_actuals', ['pattern_id' => $pattern->id, 'actual_kanban' => 20]);
    }

    public function test_actual_kanban_rejects_negative_values(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $part = Part::create(['part_no' => 'P1']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 30,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
        ]);

        $pattern = Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        $this->postJson("/andon-planning/pattern/{$pattern->id}/actual", ['actual_kanban' => -5])
            ->assertJsonValidationErrors('actual_kanban');
    }

    public function test_kanban_override_replaces_the_kesei_computed_kanban(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $part = Part::create(['part_no' => 'P1']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 30,
            'jumlah_proses' => 1,
            'total_kanban' => 10,
            'dandori' => 0,
        ]);

        $pattern = Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        // No Kesei stock-decrease data exists, so the auto-computed kanban is
        // 0 (and its caption stays hidden). A manual override should show up
        // and win over that regardless.
        $response = $this->postJson("/andon-planning/pattern/{$pattern->id}/actual", ['kanban_override' => 25]);
        $response->assertOk();
        $response->assertJson(['ok' => true, 'kanban_override' => 25]);

        $saved = PatternActual::where('pattern_id', $pattern->id)->first();
        $this->assertNotNull($saved);
        $this->assertSame(25, $saved->kanban_override);

        $html = $this->get("/andon-planning/{$board->id}")->getContent();
        $this->assertStringContainsString('value="25"', $html);
    }

    public function test_actual_kanban_and_kanban_override_save_independently_without_clobbering_each_other(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $part = Part::create(['part_no' => 'P1']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 30,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
        ]);

        $pattern = Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        $this->postJson("/andon-planning/pattern/{$pattern->id}/actual", ['actual_kanban' => 12])->assertOk();
        $this->postJson("/andon-planning/pattern/{$pattern->id}/actual", ['kanban_override' => 30])->assertOk();

        $saved = PatternActual::where('pattern_id', $pattern->id)->first();
        $this->assertSame(12, $saved->actual_kanban);
        $this->assertSame(30, $saved->kanban_override);

        // Still just the one row for the day, not two.
        $this->assertSame(1, PatternActual::where('pattern_id', $pattern->id)->count());
    }

    public function test_andon_show_planning_card_is_read_only(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $part = Part::create(['part_no' => 'P1']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 30,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
        ]);

        $pattern = Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        $this->postJson("/andon-planning/pattern/{$pattern->id}/actual", ['actual_kanban' => 8])->assertOk();

        // Andon (the monitoring board) shows the same Planning card, but it
        // must never render an editable input — just the saved value.
        $html = $this->get("/andon/{$board->id}")->getContent();
        $this->assertStringNotContainsString('andon-actual-input', $html);
        $this->assertStringNotContainsString('andon-kanban-override-input', $html);
        $this->assertStringContainsString('andon-actual-value', $html);
        $this->assertMatchesRegularExpression('/andon-actual-value[^>]*>\s*8\s*</', $html);

        // Andon Planning (the editable page) still has both inputs.
        $planningHtml = $this->get("/andon-planning/{$board->id}")->getContent();
        $this->assertStringContainsString('andon-actual-input', $planningHtml);
        $this->assertStringContainsString('andon-kanban-override-input', $planningHtml);
    }

    public function test_kosei_shows_a_closing_time_marker_for_each_parts_production_slot(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $part = Part::create(['part_no' => 'P1']);

        // Shift 2 starts at 20:00 (chart minute 1200) — closing time is 4h
        // earlier at 16:00 (minute 960), which is well after day-start so it
        // has room to be drawn.
        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'shift' => 2,
            'urutan' => 1,
            'loading_time' => 30,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'shift' => 2,
            'proses' => 1,
        ]);

        $html = $this->get("/andon/{$board->id}")->getContent();

        $this->assertStringContainsString('closing-time-marker', $html);
        // (960 - 420) * 1.8 = 972px.
        $this->assertStringContainsString('left: 972px', $html);
        $this->assertStringContainsString('Closing time 16:00', $html);
    }

    public function test_kosei_pins_a_pre_window_closing_time_marker_to_the_left_edge_instead_of_dropping_it(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $part = Part::create(['part_no' => 'P1']);

        // Production starts right at day-start (07:00) — closing time is 03:00,
        // before the chart begins. It must still be drawn: pinned to the left
        // edge, with the true time kept in the tooltip.
        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 30,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        $html = $this->get("/andon/{$board->id}")->getContent();

        $this->assertStringContainsString('closing-time-marker--pinned', $html);
        // Pinned hard against the left edge.
        $this->assertMatchesRegularExpression('/closing-time-marker--pinned"\s*style="left: 0px/', $html);
        // Tooltip still names the real (pre-window) closing time.
        $this->assertStringContainsString('Closing time 03:00', $html);
    }

    public function test_kesei_ct_column_shows_the_kanban_as_of_the_closing_time(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $part = Part::create(['part_no' => 'P1', 'qty_kbn' => 1]); // 1 pc = 1 kanban

        // Part starts at 07:00 (dandori 30) → closing time = 03:00.
        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 60,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 30,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        [$windowStart] = $this->currentStockWindow();
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 100, 'std_min' => 0, 'captured_at' => $windowStart->copy()->subHours(5)]);
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 85, 'std_min' => 0, 'captured_at' => $windowStart->copy()->subHours(4)]);

        $koseiHtml = $this->koseiPanelHtml($board->id);

        // The Kesei panel gained a "CT" (closing-time kanban) column...
        $this->assertStringContainsString('>CT</span>', $koseiHtml);

        // ...and P1's CT cell shows 15 (100 → 85 by its 03:00 closing time).
        $this->assertMatchesRegularExpression('/text-green-700">\s*15\s*</', $koseiHtml);
    }

    public function test_kesei_folds_pre_closing_red_ticks_into_an_accumulated_number_on_the_green_line(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $part = Part::create(['part_no' => 'P1', 'qty_kbn' => 1]);

        // Shift 2 → starts 20:00 → closing time 16:00, well inside the window.
        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'shift' => 2,
            'urutan' => 1,
            'loading_time' => 30,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'shift' => 2,
            'proses' => 1,
        ]);

        [$windowStart] = $this->currentStockWindow();
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 100, 'std_min' => 0, 'captured_at' => $windowStart]);                      // 07:00
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 90, 'std_min' => 0, 'captured_at' => $windowStart->copy()->addHours(3)]); // 10:00  -10
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 85, 'std_min' => 0, 'captured_at' => $windowStart->copy()->addHours(8)]); // 15:00  -5
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 70, 'std_min' => 0, 'captured_at' => $windowStart->copy()->addHours(10)]); // 17:00  -15 (after closing)

        $koseiHtml = $this->koseiPanelHtml($board->id);

        // Drops before the 16:00 closing time are no longer drawn as red ticks...
        $this->assertStringNotContainsString('stok turun 10 kanban', $koseiHtml);
        $this->assertStringNotContainsString('stok turun 5 kanban', $koseiHtml);
        // ...they're folded into the accumulated total (10 + 5 = 15) on the green line.
        $this->assertStringContainsString('whitespace-nowrap text-green-700"', $koseiHtml);
        $this->assertMatchesRegularExpression('/whitespace-nowrap text-green-700"[^>]*>15</', $koseiHtml);
        // The drop after closing still shows its red ticks — fresh accumulation.
        $this->assertStringContainsString('stok turun 15 kanban (15 pcs)', $koseiHtml);
    }

    private function koseiPanelHtml(int $boardId): string
    {
        $html = $this->get("/andon/{$boardId}")->getContent();
        $start = strpos($html, 'id="andon-panel-kosei"');

        return substr($html, $start, strpos($html, 'id="andon-panel-stock"') - $start);
    }

    private function seedBoard(string $boardName, string $partNo): PatternBoard
    {
        $board = PatternBoard::create(['name' => $boardName]);
        $machine = Machine::create(['name' => 'M-'.$partNo]);
        $part = Part::create(['part_no' => $partNo]);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 30,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        return $board;
    }

    public function test_andon_auto_shows_the_calendar_board_for_the_current_production_day(): void
    {
        $this->seedBoard('BOARD-A', 'PART-A');
        $boardB = $this->seedBoard('BOARD-B', 'PART-B');

        CalendarEntry::create(['date' => now()->toDateString(), 'pattern_board_id' => $boardB->id]);

        $html = $this->get('/andon')->getContent();

        $this->assertStringContainsString('PART-B 1/1', $html);
        $this->assertStringNotContainsString('PART-A 1/1', $html);
    }

    public function test_andon_auto_switches_to_the_next_days_board_30_minutes_before_shift_1(): void
    {
        $today = $this->seedBoard('TODAY', 'PART-TODAY');
        $tomorrow = $this->seedBoard('TOMORROW', 'PART-TOMORROW');

        CalendarEntry::create(['date' => '2026-09-10', 'pattern_board_id' => $today->id]);
        CalendarEntry::create(['date' => '2026-09-11', 'pattern_board_id' => $tomorrow->id]);

        // 06:29 on the 11th — still the 10th's pattern.
        Carbon::setTestNow('2026-09-11 06:29:00');
        $this->assertStringContainsString('PART-TODAY 1/1', $this->get('/andon')->getContent());

        // 06:30 — the board has rolled over, 30 min before the shift.
        Carbon::setTestNow('2026-09-11 06:30:00');
        $this->assertStringContainsString('PART-TOMORROW 1/1', $this->get('/andon')->getContent());

        Carbon::setTestNow();
    }

    public function test_andon_auto_shows_a_set_the_calendar_message_when_the_day_has_no_pattern(): void
    {
        $this->seedBoard('SOME-BOARD', 'SOME-PART');
        // No CalendarEntry for today.

        $this->get('/andon')
            ->assertOk()
            ->assertSee('Belum ada pattern untuk', false)
            ->assertSee('Calendar', false);
    }

    public function test_andon_auto_ajax_asks_the_browser_to_reload_when_the_day_has_no_pattern(): void
    {
        $this->seedBoard('SOME-BOARD', 'SOME-PART');

        $this->get('/andon', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertExactJson(['reload' => true, 'boardId' => null]);
    }

    public function test_andon_explicit_board_is_a_manual_override_with_a_way_back_to_auto(): void
    {
        $this->seedBoard('BOARD-A', 'PART-A');
        $boardB = $this->seedBoard('BOARD-B', 'PART-B');

        CalendarEntry::create(['date' => now()->toDateString(), 'pattern_board_id' => PatternBoard::where('name', 'BOARD-A')->first()->id]);

        $html = $this->get("/andon/{$boardB->id}")->getContent();

        // Renders the explicitly-asked board, not the Calendar's.
        $this->assertStringContainsString('PART-B 1/1', $html);
        // Flagged as a manual override, with an "Auto" link back to the
        // Calendar-driven endpoint.
        $this->assertStringContainsString('Manual</span>', $html);
        $this->assertStringContainsString(route('andon.index'), $html);
    }

    public function test_timeline_stok_now_covers_the_full_24_hours_including_06_to_07(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $part = Part::create(['part_no' => 'P1']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 10,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        [$windowStart] = $this->currentStockWindow();
        $lateTime = $windowStart->copy()->addHours(23)->addMinutes(30); // 06:30 next day
        StockSnapshot::create(['part_no' => 'P1', 'stock' => 42, 'std_min' => 0, 'captured_at' => $lateTime]);

        $html = $this->get("/andon/{$board->id}")->getContent();

        $stockPanelPos = strpos($html, 'TIMELINE STOK');
        $this->assertNotFalse($stockPanelPos);
        $timePos = strpos($html, $lateTime->format('H:i'), $stockPanelPos);
        $this->assertNotFalse($timePos, '06:00-07:00 hour must now appear in Timeline Stok');
    }

    public function test_planning_table_requires_the_manage_planning_permission(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);

        // Guest: redirected to login.
        $this->get(route('planning.table', $board))->assertRedirect(route('login'));

        // Logged in but without the permission: forbidden.
        (new RolePermissionSeeder)->run();
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff)->get(route('planning.table', $board))->assertForbidden();

        // Admin (has the permission): allowed.
        $this->actingAs($this->authorizedUser())->get(route('planning.table', $board))->assertOk();
    }

    public function test_planning_table_lists_each_assignment_with_auto_and_effective_kanban(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $part = Part::create(['part_no' => 'P1']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 30,
            'jumlah_proses' => 1,
            'total_kanban' => 5,
            'dandori' => 0,
        ]);

        $pattern = Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        $html = $this->actingAs($this->authorizedUser())
            ->get(route('planning.table', $board))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('M1', $html);
        $this->assertStringContainsString('P1 1/1', $html);
        $this->assertStringContainsString('data-pattern-id="'.$pattern->id.'"', $html);
        // No Kesei data and no override yet — auto kanban is 0, so is the
        // effective one, with no "*" override marker.
        $this->assertMatchesRegularExpression('/text-gray-800">\s*0\s*</', $html);
    }

    public function test_planning_table_shows_each_assignments_shift(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $partShift1 = Part::create(['part_no' => 'S1']);
        $partShift2 = Part::create(['part_no' => 'S2']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $partShift1->id,
            'urutan' => 1,
            'loading_time' => 30,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
            'shift' => 1,
        ]);
        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $partShift2->id,
            'urutan' => 1,
            'loading_time' => 20,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
            'shift' => 2,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $partShift1->id,
            'proses' => 1,
            'shift' => 1,
        ]);
        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $partShift2->id,
            'proses' => 1,
            'shift' => 2,
        ]);

        $html = $this->actingAs($this->authorizedUser())
            ->get(route('planning.table', $board))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/Shift\s*1/', $html);
        $this->assertMatchesRegularExpression('/Shift\s*2/', $html);
    }

    public function test_planning_table_lists_all_shift_1_rows_before_any_shift_2_row(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        // PT91 sorts before PT92 alphabetically, but it's on shift 2 — so a
        // plain machine-name sort would list it first. Shift order must win.
        $machinePT91 = Machine::create(['name' => 'PT91']);
        $machinePT92 = Machine::create(['name' => 'PT92']);
        $partShift2 = Part::create(['part_no' => 'S2']);
        $partShift1 = Part::create(['part_no' => 'S1']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $partShift2->id,
            'urutan' => 1,
            'loading_time' => 20,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
            'shift' => 2,
        ]);
        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $partShift1->id,
            'urutan' => 1,
            'loading_time' => 30,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
            'shift' => 1,
        ]);

        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machinePT91->id,
            'part_id' => $partShift2->id,
            'proses' => 1,
            'shift' => 2,
        ]);
        Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machinePT92->id,
            'part_id' => $partShift1->id,
            'proses' => 1,
            'shift' => 1,
        ]);

        $html = $this->actingAs($this->authorizedUser())
            ->get(route('planning.table', $board))
            ->assertOk()
            ->getContent();

        $shift1Pos = strpos($html, 'S1 1/1');
        $shift2Pos = strpos($html, 'S2 1/1');
        $this->assertNotFalse($shift1Pos);
        $this->assertNotFalse($shift2Pos);
        $this->assertLessThan($shift2Pos, $shift1Pos, 'shift 1 rows must be listed before shift 2 rows');
    }

    public function test_planning_table_search_filters_by_machine_or_part(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machineA = Machine::create(['name' => 'PT91']);
        $machineB = Machine::create(['name' => 'PT92']);
        $partA = Part::create(['part_no' => 'AAA']);
        $partB = Part::create(['part_no' => 'BBB']);

        foreach ([[$machineA, $partA], [$machineB, $partB]] as [$machine, $part]) {
            PatternGroupItem::create([
                'pattern_board_id' => $board->id,
                'part_id' => $part->id,
                'urutan' => $part->id,
                'loading_time' => 30,
                'jumlah_proses' => 1,
                'total_kanban' => 1,
                'dandori' => 0,
            ]);
            Pattern::create([
                'pattern_board_id' => $board->id,
                'machine_id' => $machine->id,
                'part_id' => $part->id,
                'proses' => 1,
            ]);
        }

        $html = $this->actingAs($this->authorizedUser())
            ->get(route('planning.table', $board).'?search=AAA')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('AAA', $html);
        $this->assertStringNotContainsString('BBB', $html);
    }

    public function test_updating_actual_with_produced_on_saves_to_that_date_instead_of_today(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);
        $part = Part::create(['part_no' => 'P1']);

        PatternGroupItem::create([
            'pattern_board_id' => $board->id,
            'part_id' => $part->id,
            'urutan' => 1,
            'loading_time' => 30,
            'jumlah_proses' => 1,
            'total_kanban' => 1,
            'dandori' => 0,
        ]);

        $pattern = Pattern::create([
            'pattern_board_id' => $board->id,
            'machine_id' => $machine->id,
            'part_id' => $part->id,
            'proses' => 1,
        ]);

        $yesterday = now()->subDay()->toDateString();

        $this->postJson("/andon-planning/pattern/{$pattern->id}/actual", [
            'actual_kanban' => 9,
            'produced_on' => $yesterday,
        ])->assertOk();

        $saved = PatternActual::where('pattern_id', $pattern->id)->first();
        $this->assertNotNull($saved);
        $this->assertSame($yesterday, $saved->produced_on);
        $this->assertSame(9, $saved->actual_kanban);

        // Not saved under today's date.
        [$windowStart] = $this->currentStockWindow();
        $this->assertNull(
            PatternActual::where('pattern_id', $pattern->id)
                ->where('produced_on', $windowStart->toDateString())
                ->first()
        );

        // The Planning table for that date shows it back.
        $html = $this->actingAs($this->authorizedUser())
            ->get(route('planning.table', $board).'?date='.$yesterday)
            ->getContent();
        $this->assertStringContainsString('value="9"', $html);
    }
}
