<?php

namespace App\Http\Controllers;

use App\Services\KeseiBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Standalone Kesei board. All the data is built by {@see KeseiBoard} so the
 * same component can also be embedded in the pattern-driven Andon board.
 *
 * Two flavours, identical except for where the red ticks come from:
 *  - show()     — ticks from the Stock Part All API feed
 *  - showScan() — ticks from scanned SOS labels (kesei_scans)
 */
class AndonKeseiController extends Controller
{
    public function show(Request $request, KeseiBoard $board): View|JsonResponse
    {
        return $this->render($request, $board->data('stock'), 'KESEI KANBAN LINE 9', 60000);
    }

    public function showScan(Request $request, KeseiBoard $board): View|JsonResponse
    {
        // Scans are live operator actions on a handheld — refresh fast so a
        // tick lands on the wall board almost immediately, not on the stock
        // board's 60s cadence (stock only moves every 15 minutes anyway).
        return $this->render($request, $board->data('scan'), 'KESEI SCAN LINE 9', 3000);
    }

    private function render(Request $request, array $viewData, string $title, int $pollMs): View|JsonResponse
    {
        $viewData['boardTitle'] = $title;
        $viewData['pollMs'] = $pollMs;

        if ($request->ajax()) {
            return response()->json([
                'timeline' => view('andon-kesei._timeline', $viewData)->render(),
                'closingTable' => view('andon-kesei._closing-table', $viewData)->render(),
                'stockTimeline' => view('andon-kesei._stock-timeline', $viewData)->render(),
                'serverTime' => now()->format('H:i:s'),
            ]);
        }

        return view('andon-kesei.show', $viewData);
    }
}
