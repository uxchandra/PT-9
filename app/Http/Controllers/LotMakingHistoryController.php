<?php

namespace App\Http\Controllers;

use App\Models\LotMakingScan;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class LotMakingHistoryController extends Controller
{
    private const PER_PAGE = [10, 25, 50, 100];

    public function scans(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $date = $this->date($request);
        $perPage = $this->perPage($request);

        $scans = LotMakingScan::query()
            ->with('scannedBy')
            ->when($search !== '', fn ($q) => $q->where('part_no', 'like', "%{$search}%"))
            ->when($date !== '', fn ($q) => $q->whereDate('scanned_at', $date))
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $viewData = ['scans' => $scans, 'search' => $search, 'date' => $date, 'perPage' => $perPage];

        return $request->ajax()
            ? view('lot-makings._history-scan-results', $viewData)
            : view('lot-makings.history-scan', $viewData);
    }

    private function perPage(Request $request): int
    {
        $value = (int) $request->query('per_page', 50);

        return in_array($value, self::PER_PAGE, true) ? $value : 50;
    }

    private function date(Request $request): string
    {
        $raw = trim((string) $request->query('date', ''));

        try {
            return $raw === '' ? '' : Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return '';
        }
    }
}
