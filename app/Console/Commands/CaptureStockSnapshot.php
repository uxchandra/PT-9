<?php

namespace App\Console\Commands;

use App\Models\StockSnapshot;
use App\Services\StockPartApi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('stock:capture-snapshot')]
#[Description('Fetch stock part data from the SOS source system and store an aggregated per-part snapshot for the TD process')]
class CaptureStockSnapshot extends Command
{
    /**
     * PT-9's own stock is tracked under the "TD" process in the shared SOS
     * system — other processes/stores in the same feed belong to other lines,
     * so they're excluded before aggregating per part.
     */
    private const PROCESS = 'TD';

    public function handle(StockPartApi $api): int
    {
        $rows = $api->fetchRows();

        if ($rows === null) {
            $this->error('Failed to fetch stock data from the source API.');

            return self::FAILURE;
        }

        $capturedAt = now();

        $grouped = collect($rows)
            ->filter(fn (array $row) => filled($row['part_no'] ?? null) && ($row['process'] ?? null) === self::PROCESS)
            ->groupBy('part_no');

        foreach ($grouped as $partNo => $partRows) {
            StockSnapshot::create([
                'part_no' => $partNo,
                'stock' => (int) $partRows->sum(fn (array $row) => (float) ($row['stock'] ?? 0)),
                'std_min' => (int) $partRows->sum(fn (array $row) => (float) ($row['std_min'] ?? 0)),
                'captured_at' => $capturedAt,
            ]);
        }

        $this->info("Captured stock snapshot for {$grouped->count()} parts at {$capturedAt->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
