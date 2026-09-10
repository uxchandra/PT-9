<?php

namespace App\Http\Controllers;

use App\Models\KeseiClosingNotification;
use App\Models\KeseiScan;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KeseiHistoryController extends Controller
{
    private const PER_PAGE = [10, 25, 50, 100];

    public function scans(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $perPage = $this->perPage($request);

        $scans = KeseiScan::query()
            ->with('scannedBy')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('part_no', 'like', "%{$search}%")
                ->orWhere('location', 'like', "%{$search}%")))
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $viewData = ['scans' => $scans, 'search' => $search, 'perPage' => $perPage];

        return $request->ajax()
            ? view('kesei._history-scan-results', $viewData)
            : view('kesei.history-scan', $viewData);
    }

    public function closings(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $perPage = $this->perPage($request);

        $rows = KeseiClosingNotification::query()
            ->with('keseiPart.part')
            ->when($search !== '', fn ($q) => $q->whereHas(
                'keseiPart.part',
                fn ($p) => $p->where('part_no', 'like', "%{$search}%")
            ))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $viewData = ['rows' => $rows, 'search' => $search, 'perPage' => $perPage];

        return $request->ajax()
            ? view('kesei._history-closing-results', $viewData)
            : view('kesei.history-closing', $viewData);
    }

    private function perPage(Request $request): int
    {
        $value = (int) $request->query('per_page', 50);

        return in_array($value, self::PER_PAGE, true) ? $value : 50;
    }
}
