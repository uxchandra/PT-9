<?php

namespace App\Http\Controllers;

use App\Services\HeijunkaBoxBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The "Heijunka Box" Andon board — see {@see HeijunkaBoxBoard} for the
 * mechanism. Dark, full-bleed theme, same pattern as AndonKeseiController:
 * a full page on a plain navigation, a JSON partial on the polling fetch
 * (both explicitly no-store, same reasoning as there — the same URL serves
 * two different response shapes differentiated only by a header, and a
 * cache collision between them has bitten this app before).
 */
class AndonHeijunkaBoxController extends Controller
{
    public function show(Request $request, HeijunkaBoxBoard $board): Response|JsonResponse
    {
        $viewData = $board->data();
        $viewData['boardTitle'] = 'HEIJUNKA BOX LINE 9';
        $viewData['pollMs'] = 60000;

        if ($request->ajax()) {
            return response()->json([
                'grid' => view('andon-heijunka-box._grid', $viewData)->render(),
                'serverTime' => now()->format('H:i:s'),
            ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
        }

        return response()
            ->view('andon-heijunka-box.show', $viewData)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}
