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
 * against. Every card is one of KeseiPull::LOCATIONS (Finish Goods, Store
 * 3) — there is no separate Lot Making card. A Lot Making part shows up on
 * whichever of those cards matches its own `level` (see LotMaking::LEVELS),
 * right alongside that card's Kesei parts. Scanning still writes to
 * whichever system the part belongs to: a Kesei part becomes a KeseiScan red
 * tick on the scan Andon board; a Lot Making part becomes a LotMakingScan
 * and additionally advances that part's slot-fill position (see
 * LotMakingCycleTracker) on the Andon Lot Making board. A part is expected
 * to belong to exactly one of the two — if it somehow matched both, the
 * Kesei side wins for the list figures/cap, but see scan() for exactly how.
 */
class ScannerController extends Controller
{
    public function dashboard(): View
    {
        $locations = collect(KeseiPull::LOCATIONS)->map(fn ($l) => $l['name'])->all();

        return view('scanner.dashboard', ['locations' => $locations]);
    }

    public function location(Request $request, string $location, KeseiPull $pull, LotMakingPull $lotMakingPull): View
    {
        abort_unless(KeseiPull::isLocation($location), 404);

        // Every open scanner polls this ~every 20s. The figures only move on the
        // 15-minute stock feed and on new scans (from either system), so a
        // short cache — busted by the newest scan id from each — keeps the
        // poll from rebuilding the whole board on every tick.
        $cacheKey = "scanner-pull:{$location}:"
            .(KeseiScan::where('location', $location)->max('id') ?? 0).':'
            .(LotMakingScan::max('id') ?? 0);

        $rows = collect(Cache::remember($cacheKey, 10, fn () => $pull->list($location)
            ->concat($lotMakingPull->list($location))
            ->sortBy('done')
            ->values()
            ->all()));

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
        abort_unless(KeseiPull::isLocation($location), 404);

        $raw = trim((string) $request->input('code'));
        $partNo = KeseiScan::parsePartNo($raw);

        if ($partNo === null) {
            return response()->json(['ok' => false, 'reason' => 'QR tidak dikenali.'], 422);
        }

        $keseiRow = $pull->scanRow($location, $partNo);
        // Only checked when the part isn't a Kesei part on this card — a
        // part_no isn't expected to be tracked by both systems at once, so
        // the Kesei side (if it matches) is always the one whose figures
        // and cap the response/rejection below is based on.
        $lotMakingRow = $keseiRow === null ? $lotMakingPull->scanRow($partNo, $location) : null;

        $row = $keseiRow ?? $lotMakingRow;

        if ($row === null) {
            return response()->json(['ok' => false, 'reason' => "{$partNo} tidak ada di daftar ".KeseiPull::name($location).'.'], 422);
        }

        // Only demand-mode rows (needed !== null) cap the scan count.
        if ($row['remaining'] !== null && $row['remaining'] <= 0) {
            return response()->json(['ok' => false, 'reason' => "{$partNo} sudah terpenuhi ({$row['scanned']}/{$row['needed']})."], 422);
        }

        if ($keseiRow !== null) {
            KeseiScan::create([
                'part_no' => $partNo,
                'location' => $location,
                'raw' => $raw,
                'scanned_by' => $request->user()->id,
                'scanned_at' => now(),
            ]);
        } else {
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
        }

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
