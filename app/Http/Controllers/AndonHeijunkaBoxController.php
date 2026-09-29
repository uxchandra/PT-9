<?php

namespace App\Http\Controllers;

use App\Models\CalendarEntry;
use App\Services\HeijunkaBoxBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * The "Heijunka Box" Andon board — see {@see HeijunkaBoxBoard} for the
 * mechanism. Dark, full-bleed theme, same pattern as AndonKeseiController:
 * a full page on a plain navigation, a JSON partial on the polling fetch
 * (both explicitly no-store, same reasoning as there — the same URL serves
 * two different response shapes differentiated only by a header, and a
 * cache collision between them has bitten this app before).
 *
 * ?date=Y-m-d turns it into a history view of that production day (07:00 →
 * 07:00), as it stood at the end of it — every tick that day, red or blue,
 * none of the live board's own hiding rules (see HeijunkaBoxBoard::data()).
 * Today (or anything invalid/future) stays live, same convention
 * AndonKeseiController's own Heikinka date picker uses.
 */
class AndonHeijunkaBoxController extends Controller
{
    public function show(Request $request, HeijunkaBoxBoard $board): Response|JsonResponse
    {
        $today = CalendarEntry::productionDayStart();
        $asOf = null;

        try {
            $picked = $request->query('date') ? Carbon::createFromFormat('Y-m-d', (string) $request->query('date'))->startOfDay() : null;
        } catch (\Throwable) {
            $picked = null;
        }

        if ($picked !== null && $picked->lt($today->copy()->startOfDay())) {
            $asOf = CalendarEntry::productionDayStart($picked->copy()->setTime(12, 0))->addDay()->subSecond();
        }

        $viewData = $board->data($asOf);
        $viewData['boardTitle'] = 'HEIJUNKA LINE 9';
        $viewData['pollMs'] = 60000;
        $viewData['heijunkaDate'] = $asOf !== null ? $picked->toDateString() : $today->toDateString();
        $viewData['heijunkaMaxDate'] = $today->toDateString();
        $viewData['heijunkaDateLabel'] = Carbon::parse($viewData['heijunkaDate'])->locale('id')->translatedFormat('l, d F Y');

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
