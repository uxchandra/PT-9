<?php

namespace App\Http\Controllers;

use App\Services\KeseiBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Standalone Kesei board — dark, full-bleed theme. Data is built by
 * {@see KeseiBoard}; the light andon-kesei._board/_timeline/etc. partials
 * are a separate set used only for the KESEI card embedded in the
 * pattern-driven /andon board, so this page's dark styling never bleeds
 * into that one.
 *
 * Three flavours, identical except for where the red ticks come from:
 *  - show()         — ticks from the Stock Part All API feed
 *  - showScan()      — ticks from scanned SOS labels (kesei_scans)
 *  - showHeijunka()  — the same Stock Part All feed as show(), but each
 *    part's pile is paced through its own Lead Time per Kanban first (see
 *    KeseiBoard::heijunkaEvents()) instead of appearing all at once —
 *    exactly what KeseiPull's own "Perintah Pulling" demand now uses too,
 *    for every part board-wide, not just this one.
 */
class AndonKeseiController extends Controller
{
    public function show(Request $request, KeseiBoard $board): Response|JsonResponse
    {
        return $this->render($request, $board->data('stock'), 'KESEI KANBAN LINE 9', 60000);
    }

    public function showScan(Request $request, KeseiBoard $board): Response|JsonResponse
    {
        // Scans are live operator actions on a handheld — refresh fast so a
        // tick lands on the wall board almost immediately, not on the stock
        // board's 60s cadence (stock only moves every 15 minutes anyway).
        return $this->render($request, $board->data('scan'), 'KESEI SCAN LINE 9', 3000);
    }

    public function showHeijunka(Request $request, KeseiBoard $board): Response|JsonResponse
    {
        return $this->render($request, $board->data('heijunka'), 'HEIJUNKA LINE 9', 60000);
    }

    private function render(Request $request, array $viewData, string $title, int $pollMs): Response|JsonResponse
    {
        $viewData['boardTitle'] = $title;
        $viewData['pollMs'] = $pollMs;

        // The polling JS below fetches this exact same URL (differentiated
        // only by the X-Requested-With header, no query string), so without
        // an explicit no-store here a browser/proxy cache can serve the
        // cached JSON variant back on a later plain navigation (e.g. the
        // 30-min auto-reload) — showing raw JSON instead of the page.
        if ($request->ajax()) {
            return response()->json([
                'timeline' => view('andon-kesei._timeline-dark', $viewData)->render(),
                'closingTable' => view('andon-kesei._closing-table-dark', $viewData)->render(),
                'antrianFixVolume' => view('andon-kesei._antrian-fix-volume-dark', $viewData)->render(),
                'serverTime' => now()->format('H:i:s'),
            ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
        }

        return response()
            ->view('andon-kesei.show', $viewData)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}
