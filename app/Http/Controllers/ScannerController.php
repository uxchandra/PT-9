<?php

namespace App\Http\Controllers;

use App\Models\KeseiScan;
use App\Services\KeseiPull;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Handheld barcode-scanner UI (SEUIC AutoID Q9). Dashboard with two location
 * cards; each card is a "pulling command" the operator scans SOS labels
 * against. Every accepted scan becomes a red tick on the scan Andon board.
 */
class ScannerController extends Controller
{
    public function dashboard(): View
    {
        return view('scanner.dashboard', [
            'locations' => collect(KeseiPull::LOCATIONS)->map(fn ($l) => $l['name'])->all(),
        ]);
    }

    public function location(Request $request, string $location, KeseiPull $pull): View
    {
        abort_unless(KeseiPull::isLocation($location), 404);

        // Every open scanner polls this ~every 20s. The figures only move on the
        // 15-minute stock feed and on new scans, so a short cache — busted by
        // the newest scan id — keeps the poll from rebuilding the whole board
        // on every tick.
        $cacheKey = "scanner-pull:{$location}:".(KeseiScan::where('location', $location)->max('id') ?? 0);
        $rows = collect(Cache::remember($cacheKey, 10, fn () => $pull->list($location)->all()));

        $data = [
            'slug' => $location,
            'label' => KeseiPull::name($location),
            'free' => KeseiPull::isFree($location),
            'rows' => $rows,
            // One value for the whole page: newest stock-feed update across parts.
            'lastUpdate' => $rows->pluck('last_update')->filter()->max() ?: null,
        ];

        // Polled every ~20s so the 15-minute reset shows up without a reload.
        return $request->ajax()
            ? view('scanner._pull-list', $data)
            : view('scanner.location', $data);
    }

    public function scan(Request $request, string $location, KeseiPull $pull): JsonResponse
    {
        abort_unless(KeseiPull::isLocation($location), 404);

        $raw = trim((string) $request->input('code'));
        $partNo = KeseiScan::parsePartNo($raw);

        if ($partNo === null) {
            return response()->json(['ok' => false, 'reason' => 'QR tidak dikenali.'], 422);
        }

        $row = $pull->scanRow($location, $partNo);

        if ($row === null) {
            return response()->json(['ok' => false, 'reason' => "{$partNo} tidak ada di daftar ".KeseiPull::name($location).'.'], 422);
        }

        // Only demand-mode locations cap the scan count.
        if ($row['remaining'] !== null && $row['remaining'] <= 0) {
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
            'remaining' => $row['needed'] === null ? null : max(0, $row['needed'] - $scanned),
            'done' => $row['needed'] !== null && $scanned >= $row['needed'],
        ]);
    }
}
