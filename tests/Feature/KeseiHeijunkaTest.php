<?php

namespace Tests\Feature;

use App\Models\KeseiPart;
use App\Models\Part;
use App\Models\StockSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class KeseiHeijunkaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The page header always carries a static red "Kanban Pull" legend
     * swatch (2 spans, unrelated to any actual tick) — tick-colour
     * assertions scope to the timeline panel itself so that swatch can't be
     * mistaken for an overdue tick.
     */
    private function timelinePanel(string $html): string
    {
        return substr($html, strpos($html, 'id="kesei-panel-timeline"'));
    }

    public function test_every_part_renders_as_active_regardless_of_todays_pattern(): void
    {
        // The Heijunka board isn't pattern-driven at all — a part with no
        // pattern board assigned (never "running" on a normal Kesei/Scan
        // board) must still render active (no dimming) here. It also uses
        // its own neutral slate row colour instead of the amber the other
        // boards use for "active" — see _timeline-dark.blade.php.
        $part = Part::create(['part_no' => 'HJ-NOPATTERN']);
        KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);

        $html = $this->get(route('andon-kesei.heijunka'))->getContent();
        $timeline = $this->timelinePanel($html);

        $this->assertStringNotContainsString('opacity-40', $timeline);
        $this->assertStringNotContainsString('tidak jalan di pattern', $timeline);
        $this->assertStringNotContainsString('bg-amber', $timeline);
        $this->assertStringContainsString('bg-slate-700/50', $timeline);

        // The normal Kesei board is unaffected — the same part still dims.
        $normal = $this->get(route('andon-kesei.show'))->getContent();
        $this->assertStringContainsString('tidak jalan di pattern', $normal);
    }

    public function test_only_finish_goods_level_parts_are_shown(): void
    {
        $fg = Part::create(['part_no' => 'HJ-FG']);
        KeseiPart::create(['part_id' => $fg->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);

        $store3 = Part::create(['part_no' => 'HJ-STORE3']);
        KeseiPart::create(['part_id' => $store3->id, 'level' => 'STORE 3', 'urutan' => 2]);

        $blank = Part::create(['part_no' => 'HJ-BLANK']);
        KeseiPart::create(['part_id' => $blank->id, 'urutan' => 3]);

        $html = $this->get(route('andon-kesei.heijunka'))->getContent();

        $this->assertStringContainsString('HJ-FG', $html);
        $this->assertStringNotContainsString('HJ-STORE3', $html);
        $this->assertStringNotContainsString('HJ-BLANK', $html);

        // The normal Kesei board is unaffected — it still shows every level.
        $normal = $this->get(route('andon-kesei.show'))->getContent();
        $this->assertStringContainsString('HJ-STORE3', $normal);
        $this->assertStringContainsString('HJ-BLANK', $normal);
    }

    public function test_lot_making_finish_goods_parts_share_the_same_board_as_kesei_ones(): void
    {
        $kesei = Part::create(['part_no' => 'HJ-KESEI-SIDE']);
        KeseiPart::create(['part_id' => $kesei->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);

        $lmFg = Part::create(['part_no' => 'HJ-LM-FG']);
        \App\Models\LotMaking::create(['part_id' => $lmFg->id, 'level' => 'finish-goods']);

        // Store 3 Lot Making parts have no heijunka story either, same as
        // Kesei's own Store 3 rows.
        $lmStore3 = Part::create(['part_no' => 'HJ-LM-STORE3']);
        \App\Models\LotMaking::create(['part_id' => $lmStore3->id, 'level' => 'store-3']);

        $html = $this->get(route('andon-kesei.heijunka'))->getContent();

        $this->assertStringContainsString('HJ-KESEI-SIDE', $html);
        $this->assertStringContainsString('HJ-LM-FG', $html);
        $this->assertStringNotContainsString('HJ-LM-STORE3', $html);
    }

    public function test_a_lot_making_row_on_the_heijunka_board_releases_and_recolours_ticks_the_same_way(): void
    {
        Carbon::setTestNow('2026-09-15 08:30:00');
        $part = Part::create(['part_no' => 'HJ-LM-1', 'qty_kbn' => 1]);
        \App\Models\LotMaking::create(['part_id' => $part->id, 'level' => 'finish-goods', 'lt_per_kbn' => 30]);

        StockSnapshot::create(['part_no' => 'HJ-LM-1', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);
        // -1 kanban at 08:30 — release due 09:00.
        StockSnapshot::create(['part_no' => 'HJ-LM-1', 'stock' => 99, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:30')]);

        // Still pending (not yet crossed) — already visible, green.
        Carbon::setTestNow('2026-09-15 08:59:00');
        $html = $this->get(route('andon-kesei.heijunka'))->getContent();
        $this->assertSame(1, substr_count($html, 'stok turun'));
        $this->assertSame(1, substr_count($this->timelinePanel($html), 'background-color: #22c55e'));

        // Crossed, unscanned, still within the 15-min grace period — still green.
        Carbon::setTestNow('2026-09-15 09:05:00');
        $html = $this->get(route('andon-kesei.heijunka'))->getContent();
        $this->assertSame(1, substr_count($html, 'stok turun'));
        $this->assertSame(1, substr_count($this->timelinePanel($html), 'background-color: #22c55e'));

        // A Lot Making scan (not a Kesei scan) fulfils it — turns blue.
        \App\Models\LotMakingScan::create(['part_no' => 'HJ-LM-1', 'raw' => 'HJ-LM-1', 'scanned_at' => Carbon::parse('2026-09-15 09:06')]);

        Carbon::setTestNow('2026-09-15 09:10:00');
        $html = $this->get(route('andon-kesei.heijunka'))->getContent();
        $this->assertSame(1, substr_count($this->timelinePanel($html), 'background-color: #3b82f6'));

        Carbon::setTestNow();
    }

    public function test_the_board_is_public_and_shows_the_title(): void
    {
        $response = $this->get(route('andon-kesei.heijunka'));

        $response->assertOk();
        $response->assertSee('HEIJUNKA PULLING LINE STORE');
    }

    public function test_ticks_release_one_at_a_time_paced_by_lt_per_kbn_and_stay_on_the_board_once_crossed(): void
    {
        Carbon::setTestNow('2026-09-15 08:30:00');
        $part = Part::create(['part_no' => 'HJ-1', 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1, 'lt_per_kbn' => 30]);

        StockSnapshot::create(['part_no' => 'HJ-1', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);
        // -4 kanban, captured (arrival) at 08:30 — releases due at 09:00, 09:30, 10:00, 10:30.
        StockSnapshot::create(['part_no' => 'HJ-1', 'stock' => 96, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:30')]);

        // Right at arrival — all four are queued/pending, so all four show.
        Carbon::setTestNow('2026-09-15 08:30:00');
        $html = $this->get(route('andon-kesei.heijunka'))->getContent();
        $this->assertSame(4, substr_count($html, 'stok turun'));

        // One minute before the first release — still all four pending.
        Carbon::setTestNow('2026-09-15 08:59:00');
        $html = $this->get(route('andon-kesei.heijunka'))->getContent();
        $this->assertSame(4, substr_count($html, 'stok turun'));

        // Exactly at the first release — it's "thrown" to Perintah Pulling
        // (see the KeseiPull-focused test below) but NOT removed from the
        // board — no scans have happened, so it's just unscanned-but-fresh
        // (still green) for the first 15 minutes.
        Carbon::setTestNow('2026-09-15 09:00:00');
        $html = $this->get(route('andon-kesei.heijunka'))->getContent();
        $this->assertSame(4, substr_count($html, 'stok turun'));
        $this->assertSame(4, substr_count($this->timelinePanel($html), 'background-color: #22c55e'));

        // 16 minutes after the first release, still unscanned — it flips to
        // overdue (red); the rest (not yet crossed) stay green.
        Carbon::setTestNow('2026-09-15 09:16:00');
        $html = $this->get(route('andon-kesei.heijunka'))->getContent();
        $this->assertSame(4, substr_count($html, 'stok turun'));
        $this->assertSame(1, substr_count($this->timelinePanel($html), 'background-color: #ff3b3b'));
        $this->assertSame(3, substr_count($this->timelinePanel($html), 'background-color: #22c55e'));

        // Long after every release — all four crossed, none scanned, all
        // overdue. Still all four present, none removed.
        Carbon::setTestNow('2026-09-15 12:00:00');
        $html = $this->get(route('andon-kesei.heijunka'))->getContent();
        $this->assertSame(4, substr_count($html, 'stok turun'));
        $this->assertSame(4, substr_count($this->timelinePanel($html), 'background-color: #ff3b3b'));

        Carbon::setTestNow();
    }

    public function test_a_scanned_crossed_tick_turns_blue(): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        $part = Part::create(['part_no' => 'HJ-SCAN', 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);

        StockSnapshot::create(['part_no' => 'HJ-SCAN', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 07:00')]);
        // -1 kanban, no LT/KBN pacing, so it releases (and crosses) immediately at 10:00.
        StockSnapshot::create(['part_no' => 'HJ-SCAN', 'stock' => 99, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 10:00')]);

        \App\Models\KeseiScan::create(['part_no' => 'HJ-SCAN', 'location' => 'finish-goods', 'raw' => 'HJ-SCAN', 'scanned_by' => \App\Models\User::factory()->create()->id, 'scanned_at' => Carbon::parse('2026-09-15 10:05')]);

        $html = $this->get(route('andon-kesei.heijunka'))->getContent();
        $timeline = $this->timelinePanel($html);

        $this->assertSame(1, substr_count($html, 'stok turun'));
        $this->assertSame(1, substr_count($timeline, 'background-color: #3b82f6'));
        $this->assertStringNotContainsString('background-color: #ff3b3b', $timeline);
        $this->assertStringNotContainsString('background-color: #22c55e', $timeline);

        Carbon::setTestNow();
    }

    public function test_a_scanned_tick_disappears_once_its_crossing_is_more_than_24_hours_old_but_a_fresher_one_does_not(): void
    {
        // A scanned (blue) tick was briefly kept forever, but a part with no
        // closing time configured would then pile up tick lines from many
        // different calendar days onto the one wrapping 24h clock face,
        // aliasing on top of each other at the same clock position — more
        // confusing than useful. So it's capped at 24h too, same as an
        // unscanned/overdue one.
        Carbon::setTestNow('2026-09-17 12:00:00');
        $part = Part::create(['part_no' => 'HJ-STALE', 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);

        StockSnapshot::create(['part_no' => 'HJ-STALE', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 07:00')]);
        // Crossed 2 days ago — scanned, but stale (>24h) — must drop.
        StockSnapshot::create(['part_no' => 'HJ-STALE', 'stock' => 99, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);
        // Crossed 4 hours ago, also scanned — recent enough to stay (blue).
        StockSnapshot::create(['part_no' => 'HJ-STALE', 'stock' => 98, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-17 08:00')]);

        // Two scans — enough to mark BOTH crossed ticks as scanned (FIFO,
        // oldest release first).
        \App\Models\KeseiScan::create(['part_no' => 'HJ-STALE', 'location' => 'finish-goods', 'raw' => 'HJ-STALE', 'scanned_by' => \App\Models\User::factory()->create()->id, 'scanned_at' => Carbon::parse('2026-09-15 08:05')]);
        \App\Models\KeseiScan::create(['part_no' => 'HJ-STALE', 'location' => 'finish-goods', 'raw' => 'HJ-STALE', 'scanned_by' => \App\Models\User::factory()->create()->id, 'scanned_at' => Carbon::parse('2026-09-17 08:05')]);

        $html = $this->get(route('andon-kesei.heijunka'))->getContent();
        $timeline = $this->timelinePanel($html);

        // Only the 4-hour-old one remains, still blue.
        $this->assertSame(1, substr_count($html, 'stok turun'));
        $this->assertSame(1, substr_count($timeline, 'background-color: #3b82f6'));
        $this->assertStringNotContainsString('15/09', $html);

        // The fixed "Total" footer's 08:00 bucket only counts the surviving one.
        $footer = substr($html, strpos($html, 'id="kesei-timeline-footer-scroll"'));
        $this->assertStringContainsString('color: #facc15;">1</span>', $footer);

        Carbon::setTestNow();
    }

    public function test_an_unscanned_overdue_tick_older_than_24h_stops_showing_but_still_counts_toward_perintah_pulling(): void
    {
        // Heijunka isn't governed by Closing Time at all — a part with no
        // closing configured (the common case here) would otherwise let an
        // unscanned tick pile up forever, spanning several different
        // calendar days aliased onto the one 24h clock face. The DISPLAY
        // caps at 24h regardless of scan status; Perintah Pulling demand
        // (KeseiPull) must still count it — that accounting doesn't care
        // how old the unfulfilled kanban is.
        Carbon::setTestNow('2026-09-15 12:00:00');
        $part = Part::create(['part_no' => 'HJ-OLD', 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);

        StockSnapshot::create(['part_no' => 'HJ-OLD', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-13 07:00')]);
        // -1 kanban, crossed 2 days ago, never scanned.
        StockSnapshot::create(['part_no' => 'HJ-OLD', 'stock' => 99, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-13 08:00')]);

        $html = $this->get(route('andon-kesei.heijunka'))->getContent();
        $this->assertStringNotContainsString('stok turun', $html);

        $needed = app(\App\Services\KeseiPull::class)->scanRow('finish-goods', 'HJ-OLD');
        $this->assertSame(1, $needed['needed']);

        Carbon::setTestNow();
    }

    public function test_a_new_batch_arriving_mid_drain_continues_the_queue_instead_of_bursting_again(): void
    {
        Carbon::setTestNow('2026-09-15 08:30:00');
        $part = Part::create(['part_no' => 'HJ-2', 'qty_kbn' => 1]);
        KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1, 'lt_per_kbn' => 30]);

        StockSnapshot::create(['part_no' => 'HJ-2', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);
        // Batch 1: -2 kanban at 08:30 -> releases due 09:00, 09:30.
        StockSnapshot::create(['part_no' => 'HJ-2', 'stock' => 98, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:30')]);
        // Batch 2: -1 kanban at 08:45 — arrives while batch 1's queue is
        // still draining (next free slot isn't until 09:30) — its own
        // release must continue from there (10:00), not restart from its
        // own arrival + 30min (09:15).
        StockSnapshot::create(['part_no' => 'HJ-2', 'stock' => 97, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:45')]);

        // Just before what would've been "arrival + 30min" for batch 2
        // (09:15) — if it wrongly burst on its own schedule, needed would
        // already be 2 by here (one from each batch). It must still be 1.
        Carbon::setTestNow('2026-09-15 09:16:00');
        $needed = app(\App\Services\KeseiPull::class)->scanRow('finish-goods', 'HJ-2');
        $this->assertSame(1, $needed['needed']);

        // At 09:30 — batch 1's second unit is due, batch 2's own unit isn't
        // due until the queue frees up at 09:30 + 30 = 10:00.
        Carbon::setTestNow('2026-09-15 09:30:00');
        $needed = app(\App\Services\KeseiPull::class)->scanRow('finish-goods', 'HJ-2');
        $this->assertSame(2, $needed['needed']);

        // At 10:00 — batch 2's unit finally releases, continuing the queue.
        Carbon::setTestNow('2026-09-15 10:00:00');
        $needed = app(\App\Services\KeseiPull::class)->scanRow('finish-goods', 'HJ-2');
        $this->assertSame(3, $needed['needed']);

        Carbon::setTestNow();
    }

    public function test_a_part_with_no_lt_per_kbn_still_releases_immediately_like_before(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $part = Part::create(['part_no' => 'HJ-NONE', 'qty_kbn' => 1]);
        // lt_per_kbn left null on purpose.
        KeseiPart::create(['part_id' => $part->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);

        StockSnapshot::create(['part_no' => 'HJ-NONE', 'stock' => 100, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:00')]);
        StockSnapshot::create(['part_no' => 'HJ-NONE', 'stock' => 96, 'std_min' => 0, 'captured_at' => Carbon::parse('2026-09-15 08:30')]);

        // Scanner "Perintah Pulling" — unaffected by the heijunka change.
        $this->actingAs($this->scannerUser())
            ->get(route('scanner.location', 'finish-goods'))
            ->assertOk()
            ->assertSee('HJ-NONE')
            ->assertSee('/ 4');

        Carbon::setTestNow();
    }

    private function scannerUser(): \App\Models\User
    {
        (new \Database\Seeders\RolePermissionSeeder)->run();

        $user = \App\Models\User::factory()->create();
        $user->assignRole('scanner');

        return $user;
    }
}
