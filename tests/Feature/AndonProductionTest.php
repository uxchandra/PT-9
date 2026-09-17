<?php

namespace Tests\Feature;

use App\Models\KeseiPart;
use App\Models\LotMaking;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AndonProductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_board_is_public_and_shows_the_title(): void
    {
        $response = $this->get(route('andon-production.show'));

        $response->assertOk();
        $response->assertSee('ANDON PRODUCTION LINE 9');
    }

    public function test_the_board_combines_kesei_lot_making_and_the_kesei_sidebar(): void
    {
        $keseiPart = Part::create(['part_no' => 'KESEI-PROD']);
        KeseiPart::create(['part_id' => $keseiPart->id, 'level' => 'FINISH GOODS', 'urutan' => 1]);

        $lotMakingPart = Part::create(['part_no' => 'LM-PROD']);
        LotMaking::create(['part_id' => $lotMakingPart->id, 'lot_produksi' => 10, 'slot' => 2]);

        $html = $this->get(route('andon-production.show'))->getContent();

        $this->assertStringContainsString('KESEI-PROD', $html);
        $this->assertStringContainsString('LM-PROD', $html);
        $this->assertStringContainsString('CLOSING TIME (FIX TIME)', $html);
        $this->assertStringContainsString('ANTRIAN (FIX VOLUME)', $html);
        $this->assertStringContainsString('LOT MAKING', $html);
    }

    public function test_the_lot_making_roller_panel_is_not_included(): void
    {
        // Only the grid (left 75% of the standalone board) belongs here —
        // the roller panel's own container id must not appear.
        $html = $this->get(route('andon-production.show'))->getContent();

        $this->assertStringNotContainsString('lot-making-panel-roller', $html);
    }

    public function test_the_ajax_poll_returns_all_four_panels(): void
    {
        $response = $this->get(route('andon-production.show'), ['X-Requested-With' => 'XMLHttpRequest']);

        $response->assertOk();
        $json = $response->json();

        $this->assertArrayHasKey('keseiTimeline', $json);
        $this->assertArrayHasKey('lotMakingGrid', $json);
        $this->assertArrayHasKey('closingTable', $json);
        $this->assertArrayHasKey('antrianFixVolume', $json);
    }

    public function test_the_response_is_never_cached(): void
    {
        $response = $this->get(route('andon-production.show'));

        $response->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
