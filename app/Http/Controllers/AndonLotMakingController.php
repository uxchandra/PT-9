<?php

namespace App\Http\Controllers;

use App\Services\LotMakingBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Standalone Andon Lot Making boards: the 75% window is the row/kolom/slot
 * grid built by {@see LotMakingBoard}, ticks and all; the remaining 25% is
 * the roller panel of completed cycles.
 *
 * Two flavours, identical layout, different tick source — same idea as
 * AndonKeseiController's show()/showScan() split:
 *  - show()       — "Lot Making 1": ticks from real operator scans
 *  - showDemand() — "Lot Making 2": ticks straight from the SOS
 *    kanban-pull/stock-decrease feed, no scan required
 */
class AndonLotMakingController extends Controller
{
    public function show(Request $request, LotMakingBoard $board): Response|JsonResponse
    {
        // Scans are live operator actions — poll fast, same as Andon Kesei
        // Scan, so a tick/roller entry shows up on the wall board almost
        // immediately.
        return $this->render($request, $board->data('scan'), 'LOT MAKING BY SCAN LINE 9', 3000);
    }

    public function showDemand(Request $request, LotMakingBoard $board): Response|JsonResponse
    {
        // Ticks come straight from the stock feed, which only moves every 15
        // minutes — no need to poll as fast as the scan-driven board.
        return $this->render($request, $board->data('demand'), 'LOT MAKING REALTIME SOS LINE 9', 15000);
    }

    private function render(Request $request, array $viewData, string $boardTitle, int $pollMs): Response|JsonResponse
    {
        $viewData['boardTitle'] = $boardTitle;
        $viewData['pollMs'] = $pollMs;

        if ($request->ajax()) {
            return response()->json([
                'grid' => view('andon-lot-making._grid', $viewData)->render(),
                'roller' => view('andon-lot-making._roller', $viewData)->render(),
                'serverTime' => now()->format('H:i:s'),
            ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        }

        // A wall-mounted board left open for days must never be served from a
        // proxy/browser cache — every load (and every poll) has to hit the DB.
        return response()
            ->view('andon-lot-making.show', $viewData)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
}
