<?php

namespace App\Http\Controllers;

use App\Models\StockSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * A plain historical view of the recorded stock snapshots — the same "time ×
 * parts" layout as the Andon Timeline Stok, browsable per production day.
 */
class StockSnapshotController extends Controller
{
    private const BOARD_SWITCH_MINUTE = 6 * 60 + 30;

    public function index(Request $request): View
    {
        $date = $this->resolveDate($request->query('date'));
        $search = trim((string) $request->query('q', ''));

        $windowStart = Carbon::parse($date)->setTime(7, 0);
        $windowEnd = $windowStart->copy()->addHours(24);

        // Only parts still used by Pattern / Kesei (or a Kesei stock source) —
        // so old rows for parts that dropped out no longer clutter the history.
        $snapshots = StockSnapshot::query()
            ->whereBetween('captured_at', [$windowStart, $windowEnd])
            ->whereIn('part_no', StockSnapshot::monitoredPartNos())
            ->when($search !== '', fn ($q) => $q->where('part_no', 'like', "%{$search}%"))
            ->orderBy('captured_at')
            ->get(['part_no', 'stock', 'std_min', 'captured_at']);

        $partNos = $snapshots->pluck('part_no')->unique()->sort()->values();

        $rows = $snapshots
            ->groupBy(fn (StockSnapshot $s) => $s->captured_at->toDateTimeString())
            ->map(fn ($group, $timestamp) => [
                'time' => Carbon::parse($timestamp)->format('H:i'),
                'values' => $group->mapWithKeys(fn (StockSnapshot $s) => [
                    $s->part_no => ['stock' => $s->stock, 'under_min' => $s->stock < $s->std_min],
                ]),
            ])
            ->values();

        $viewData = [
            'date' => $date,
            'search' => $search,
            'partNos' => $partNos,
            'rows' => $rows,
            'prevDate' => Carbon::parse($date)->subDay()->toDateString(),
            'nextDate' => Carbon::parse($date)->addDay()->toDateString(),
            'dateLabel' => Carbon::parse($date)->locale('id')->translatedFormat('l, d F Y'),
        ];

        if ($request->ajax()) {
            return view('stock-snapshots._results', $viewData);
        }

        return view('stock-snapshots.index', $viewData);
    }

    /**
     * A ?date= value if it parses, otherwise the current production day (before
     * 06:30 still counts as yesterday, same as the Andon boards).
     */
    private function resolveDate(?string $date): string
    {
        if ($date) {
            try {
                return Carbon::parse($date)->toDateString();
            } catch (\Throwable) {
                // fall through
            }
        }

        $now = now();
        $switch = $now->copy()->startOfDay()->addMinutes(self::BOARD_SWITCH_MINUTE);

        return ($now->lt($switch) ? $now->copy()->subDay() : $now)->toDateString();
    }
}
