<?php

namespace App\Http\Controllers;

use App\Models\KeseiScan;
use App\Services\KeseiPull;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Handheld barcode-scanner UI (SEUIC AutoID Q9). Dashboard with two location
 * cards; each card is a "pulling command" the operator scans SOS labels
 * against. Every accepted scan becomes a red tick on the scan Andon board.
 */
class ScannerController extends Controller
{
    private const LOCATION_NAMES = [
        'finish-goods' => 'Finish Goods',
        'store-3' => 'Store 3',
    ];

    public function dashboard(): View
    {
        return view('scanner.dashboard', ['locations' => self::LOCATION_NAMES]);
    }

    public function location(string $location, KeseiPull $pull): View
    {
        abort_unless(KeseiPull::isLocation($location), 404);

        return view('scanner.location', [
            'slug' => $location,
            'label' => self::LOCATION_NAMES[$location],
            'rows' => $pull->list($location),
        ]);
    }

    public function scan(Request $request, string $location, KeseiPull $pull): JsonResponse
    {
        abort_unless(KeseiPull::isLocation($location), 404);

        $raw = trim((string) $request->input('code'));
        $partNo = KeseiScan::parsePartNo($raw);

        if ($partNo === null) {
            return response()->json(['ok' => false, 'reason' => 'QR tidak dikenali.'], 422);
        }

        $row = $pull->rowFor($location, $partNo);

        if ($row === null) {
            return response()->json(['ok' => false, 'reason' => "{$partNo} tidak ada di daftar pulling."], 422);
        }

        if ($row['remaining'] <= 0) {
            return response()->json(['ok' => false, 'reason' => "{$partNo} sudah terpenuhi ({$row['scanned']}/{$row['needed']})."], 422);
        }

        KeseiScan::create([
            'part_no' => $partNo,
            'location' => $location,
            'raw' => $raw,
            'scanned_by' => $request->user()->id,
            'scanned_at' => now(),
        ]);

        $scanned = $row['scanned'] + 1;

        return response()->json([
            'ok' => true,
            'part_no' => $partNo,
            'scanned' => $scanned,
            'needed' => $row['needed'],
            'remaining' => max(0, $row['needed'] - $scanned),
            'done' => $scanned >= $row['needed'],
        ]);
    }
}
