<?php

namespace App\Http\Controllers;

use App\Services\LotMakingBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Standalone Andon Lot Making board: the 75% window is the row/kolom/slot
 * grid built by {@see LotMakingBoard}, ticks and all; the remaining 25% is
 * the roller panel of completed cycles.
 */
class AndonLotMakingController extends Controller
{
    public function show(Request $request, LotMakingBoard $board): Response|JsonResponse
    {
        $viewData = $board->data();

        // Scans are live operator actions — poll fast, same as Andon Kesei
        // Scan, so a tick/roller entry shows up on the wall board almost
        // immediately.
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
