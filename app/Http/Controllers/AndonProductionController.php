<?php

namespace App\Http\Controllers;

use App\Services\KeseiBoard;
use App\Services\LotMakingBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "Andon Production Line 9" — one wall-board combining three panels that
 * would otherwise need their own page: Kesei's timeline (left), the Lot
 * Making slot-fill grid (middle — the same grid as the left 75% of the
 * standalone /andon-lot-making board, roller panel left out on purpose),
 * and Kesei's own Closing Time + Antrian sidebar (right). Kesei's timeline
 * here works backwards from the standalone board: the "now" line stays put
 * on screen and the timeline scrolls underneath it instead — see
 * andon-production._kesei-timeline.
 */
class AndonProductionController extends Controller
{
    public function show(Request $request, KeseiBoard $keseiBoard, LotMakingBoard $lotMakingBoard): Response|JsonResponse
    {
        $viewData = $keseiBoard->data('stock');
        $viewData['lotMakingRows'] = $lotMakingBoard->data('demand')['rows'];
        $viewData['boardTitle'] = 'ANDON PRODUCTION LINE 9';
        $viewData['pollMs'] = 15000;

        // Polled regularly so the board never goes stale on an unattended
        // wall display — never served from a browser/proxy cache (see the
        // scanner/Andon Kesei bug this same pattern fixed earlier).
        if ($request->ajax()) {
            return response()->json([
                'keseiTimeline' => view('andon-production._kesei-timeline', $viewData)->render(),
                'lotMakingGrid' => view('andon-lot-making._grid', ['rows' => $viewData['lotMakingRows']])->render(),
                'closingTable' => view('andon-kesei._closing-table-dark', $viewData)->render(),
                'antrianFixVolume' => view('andon-kesei._antrian-fix-volume-dark', $viewData)->render(),
                'serverTime' => now()->format('H:i:s'),
            ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
        }

        return response()
            ->view('andon-production.show', $viewData)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}
