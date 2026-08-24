<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class StockPartApi
{
    private const API_URL = 'https://sos.step.co.id/sos/Ajax/tData';

    /**
     * Fetch the raw stock part rows from the SOS source system.
     *
     * @return array<int, array<string, mixed>>|null Null when the request fails.
     */
    public function fetchRows(): ?array
    {
        $response = Http::timeout(15)->get(self::API_URL, [
            'table' => 'tbl_stock_part',
            'api' => '644fa838028a9f20a84d0bc1872c719b306042c6',
            'menuid' => 'stockstockpart',
        ]);

        return $response->successful() ? $response->json('data', []) : null;
    }
}
