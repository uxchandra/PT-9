<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\Part;
use App\Models\Pattern;
use App\Models\PatternBoard;
use App\Models\PatternGroupItem;
use App\Models\Rest;
use App\Models\StockSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AndonTest extends TestCase
{
    use RefreshDatabase;

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

        // One continuous segment (div): title attribute + 1 visible span = 2 occurrences.
        // If this were split around the rest it would be 3+ (regression guard).
        $this->assertSame(2, substr_count($html, 'P1 1/2'));

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

    public function test_andon_show_orders_each_machines_blocks_by_assignment_creation_order_not_group_item_urutan(): void
    {
        $board = PatternBoard::create(['name' => 'TestBoard']);
        $machine = Machine::create(['name' => 'M1']);

        $partHigh = Part::create(['part_no' => 'PartHigh']); // urutan=1, listed first in Kelompok Pattern
        $partLow = Part::create(['part_no' => 'PartLow']);   // urutan=2, listed second

        PatternGroupItem::create([
            'pattern_board_id' => $board->id, 'part_id' => $partHigh->id,
            'urutan' => 1, 'loading_time' => 10, 'jumlah_proses' => 1, 'total_kanban' => 1, 'dandori' => 0,
        ]);
        PatternGroupItem::create([
            'pattern_board_id' => $board->id, 'part_id' => $partLow->id,
            'urutan' => 2, 'loading_time' => 10, 'jumlah_proses' => 1, 'total_kanban' => 1, 'dandori' => 0,
        ]);

        // Assignment for the urutan=2 part is created (imported) FIRST for this
        // machine — the andon board must schedule it first too, ignoring urutan.
        Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $machine->id, 'part_id' => $partLow->id, 'proses' => 1]);
        Pattern::create(['pattern_board_id' => $board->id, 'machine_id' => $machine->id, 'part_id' => $partHigh->id, 'proses' => 1]);

        $html = $this->get("/andon/{$board->id}")->getContent();

        $posLow = strpos($html, 'PartLow');
        $posHigh = strpos($html, 'PartHigh');

        $this->assertNotFalse($posLow);
        $this->assertNotFalse($posHigh);
        $this->assertTrue($posLow < $posHigh, 'blocks must follow assignment creation/import order, not Kelompok Pattern urutan');
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

    /** @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon} */
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
        $response->assertJsonStructure(['timeline', 'kosei', 'stockTimeline', 'nowMinute', 'serverTime']);

        // The 3 panel fragments must not include the full page shell (no
        // duplicate <html>/board switcher) — just the fragment markup itself.
        $data = $response->json();
        $this->assertStringContainsString('P1', $data['timeline']);
        $this->assertStringContainsString('P1', $data['kosei']);
        $this->assertStringNotContainsString('<html', $data['timeline']);
        $this->assertIsInt($data['nowMinute']);
    }
}
