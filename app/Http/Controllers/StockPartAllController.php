<?php

namespace App\Http\Controllers;

use App\Services\StockPartApi;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StockPartAllController extends Controller
{
    private const CACHE_KEY = 'stock-part-all-data';

    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public function index(Request $request, StockPartApi $api): View
    {
        $search = trim((string) $request->query('q', ''));
        $perPage = (int) $request->query('per_page', 25);

        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 25;
        }

        $page = LengthAwarePaginator::resolveCurrentPage();

        $rows = Cache::remember(self::CACHE_KEY, now()->addMinute(), fn () => $api->fetchRows());

        $error = $rows === null
            ? 'Gagal mengambil data dari sistem stock part. Silakan coba lagi beberapa saat.'
            : null;

        $items = collect($rows ?? []);

        if ($search !== '') {
            $items = $items->filter(function (array $row) use ($search) {
                $haystack = Str::lower(implode(' ', [
                    $row['part_no'] ?? '',
                    $row['part_no_fg'] ?? '',
                    $row['store'] ?? '',
                    $row['line'] ?? '',
                    $row['process'] ?? '',
                    $row['customer'] ?? '',
                    $row['rack_no'] ?? '',
                ]));

                return str_contains($haystack, Str::lower($search));
            })->values();
        }

        $stockParts = new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        if ($request->ajax()) {
            return view('stock-part-all._results', compact('stockParts', 'error'));
        }

        return view('stock-part-all.index', compact('stockParts', 'search', 'perPage', 'error'));
    }
}
