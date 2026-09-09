<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * Handheld barcode-scanner UI (SEUIC AutoID Q9). Deliberately tiny and
 * touch-first — one dashboard, two location cards.
 */
class ScannerController extends Controller
{
    /** slug => display name */
    private const LOCATIONS = [
        'finish-goods' => 'Finish Goods',
        'store-3' => 'Store 3',
    ];

    public function dashboard(): View
    {
        return view('scanner.dashboard', [
            'locations' => self::LOCATIONS,
        ]);
    }

    public function location(string $location): View
    {
        abort_unless(array_key_exists($location, self::LOCATIONS), 404);

        return view('scanner.location', [
            'slug' => $location,
            'label' => self::LOCATIONS[$location],
        ]);
    }
}
