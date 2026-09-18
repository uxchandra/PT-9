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

    public function test_the_board_is_public_and_shows_the_title(): void
    {
        $response = $this->get(route('andon-kesei.heijunka'));

        $response->assertOk();
        $response->assertSee('HEIJUNKA LINE 9');
    }

    public function test_ticks_release_one_at_a_time_paced_by_lt_per_kbn_instead_of_all_at_once(): void
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
        // and drops off the board, leaving three still pending.
        Carbon::setTestNow('2026-09-15 09:00:00');
        $html = $this->get(route('andon-kesei.heijunka'))->getContent();
        $this->assertSame(3, substr_count($html, 'stok turun'));

        // Halfway to the third release — two have crossed (09:00, 09:30),
        // two still pending (10:00, 10:30).
        Carbon::setTestNow('2026-09-15 09:45:00');
        $html = $this->get(route('andon-kesei.heijunka'))->getContent();
        $this->assertSame(2, substr_count($html, 'stok turun'));

        // Past the last (30*4 = 120min after arrival) — all four have
        // crossed and none remain on the board.
        Carbon::setTestNow('2026-09-15 10:31:00');
        $html = $this->get(route('andon-kesei.heijunka'))->getContent();
        $this->assertSame(0, substr_count($html, 'stok turun'));

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
