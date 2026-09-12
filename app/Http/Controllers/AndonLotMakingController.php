<?php

namespace App\Http\Controllers;

use App\Services\LotMakingBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Standalone Andon Lot Making board: the 75% window is the row/kolom/slot
 * grid built by {@see LotMakingBoard}, ticks and all; the remaining 25% is
 * the roller panel of completed cycles.
 */
class AndonLotMakingController extends Controller
{
    public function show(Request $request, LotMakingBoard $board): View|JsonResponse
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
            ]);
        }

        return view('andon-lot-making.show', $viewData);
    }
}
