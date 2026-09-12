<?php

namespace App\Http\Controllers;

use App\Models\KeseiScan;
use App\Models\LotMakingScan;
use App\Services\KeseiPull;
use App\Services\LotMakingCycleTracker;
use App\Services\LotMakingPull;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Handheld barcode-scanner UI (SEUIC AutoID Q9). Dashboard with location
 * cards; each card is a "pulling command" the operator scans SOS labels
 * against. Kesei cards (Finish Goods, Store 3) go through KeseiPull and
 * become red ticks on the scan Andon board; the Lot Making card goes through
 * LotMakingPull and additionally advances that part's slot-fill position
 * (see LotMakingCycleTracker) on the Andon Lot Making board.
 */
class ScannerController extends Controller
{
    private const LOT_MAKING = 'lot-making';

    public function dashboard(): View
    {
        $locations = collect(KeseiPull::LOCATIONS)->map(fn ($l) => $l['name'])->all();
        $locations[self::LOT_MAKING] = 'Lot Making';

        return view('scanner.dashboard', ['locations' => $locations]);
    }

    public function location(Request $request, string $location, KeseiPull $pull, LotMakingPull $lotMakingPull): View
    {
        if ($location === self::LOT_MAKING) {
            return $this->lotMakingLocation($request, $lotMakingPull);
        }

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
            // One value for the whole page: when the 15-minute stock feed last
            // captured — matches Timeline Stok, ticks every interval.
            'lastUpdate' => $pull->lastStockUpdate(),
        ];

        // Polled every ~20s so the 15-minute reset shows up without a reload.
        return $request->ajax()
            ? view('scanner._pull-list', $data)
            : view('scanner.location', $data);
    }

    public function scan(Request $request, string $location, KeseiPull $pull, LotMakingPull $lotMakingPull, LotMakingCycleTracker $cycles): JsonResponse
    {
        if ($location === self::LOT_MAKING) {
            return $this->scanLotMaking($request, $lotMakingPull, $cycles);
        }

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

    private function lotMakingLocation(Request $request, LotMakingPull $lotMakingPull): View
    {
        $cacheKey = 'scanner-pull:lot-making:'.(LotMakingScan::max('id') ?? 0);
        $rows = collect(Cache::remember($cacheKey, 10, fn () => $lotMakingPull->list()->all()));

        $data = [
            'slug' => self::LOT_MAKING,
            'label' => 'Lot Making',
            'free' => false,
            'rows' => $rows,
            'lastUpdate' => $rows->pluck('last_update')->filter()->max() ?: null,
        ];

        return $request->ajax()
            ? view('scanner._pull-list', $data)
            : view('scanner.location', $data);
    }

    private function scanLotMaking(Request $request, LotMakingPull $lotMakingPull, LotMakingCycleTracker $cycles): JsonResponse
    {
        $raw = trim((string) $request->input('code'));
        $partNo = LotMakingScan::parsePartNo($raw);

        if ($partNo === null) {
            return response()->json(['ok' => false, 'reason' => 'QR tidak dikenali.'], 422);
        }

        $row = $lotMakingPull->scanRow($partNo);

        if ($row === null) {
            return response()->json(['ok' => false, 'reason' => "{$partNo} tidak ada di daftar Lot Making."], 422);
        }

        if ($row['remaining'] <= 0) {
            return response()->json(['ok' => false, 'reason' => "{$partNo} sudah terpenuhi ({$row['scanned']}/{$row['needed']})."], 422);
        }

        LotMakingScan::create([
            'part_no' => $partNo,
            'raw' => $raw,
            'scanned_by' => $request->user()->id,
            'scanned_at' => now(),
        ]);

        // Independent of the demand check above — advances the slot-fill
        // position and logs a completed cycle to the Andon roller if this
        // scan just filled the last slot.
        $cycles->checkForCompletion($partNo);

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
