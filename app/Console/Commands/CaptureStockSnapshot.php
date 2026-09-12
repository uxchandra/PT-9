<?php

namespace App\Console\Commands;

use App\Models\LotMaking;
use App\Models\StockSnapshot;
use App\Services\KeseiClosingNotifier;
use App\Services\LotMakingDemandCycleTracker;
use App\Services\StockPartApi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('stock:capture-snapshot')]
#[Description('Fetch stock part data from the SOS source system and store an aggregated per-part TD snapshot, limited to parts used by Pattern, Kesei, or Lot Making')]
class CaptureStockSnapshot extends Command
{
    /**
     * PT-9's own stock is tracked under the "TD" process in the shared SOS
     * system — other processes/stores in the same feed belong to other lines,
     * so they're excluded before aggregating per part.
     */
    private const PROCESS = 'TD';

    public function handle(StockPartApi $api, KeseiClosingNotifier $keseiNotifier, LotMakingDemandCycleTracker $demandCycles): int
    {
        // Piggybacks on this 15-minute tick: a WhatsApp goes out when a Kesei
        // part reaches its closing time. Wrapped so a notifier hiccup never
        // blocks the actual stock capture.
        try {
            $notify = $keseiNotifier->run();
            if ($notify['sent'] > 0 || $notify['failed'] > 0) {
                $this->info("Kesei closing WhatsApp: {$notify['sent']} sent, {$notify['failed']} failed.");
            }
        } catch (\Throwable $e) {
            Log::warning('KeseiClosingNotifier failed during stock:capture-snapshot', ['error' => $e->getMessage()]);
        }

        $rows = $api->fetchRows();

        if ($rows === null) {
            $this->error('Failed to fetch stock data from the source API.');

            return self::FAILURE;
        }

        $wanted = StockSnapshot::monitoredPartNos();

        if ($wanted->isEmpty()) {
            $this->warn('No parts are registered in Pattern or Kesei — nothing captured.');

            return self::SUCCESS;
        }

        $capturedAt = now();

        $grouped = collect($rows)
            ->filter(fn (array $row) => filled($row['part_no'] ?? null)
                && ($row['process'] ?? null) === self::PROCESS
                && $wanted->contains($row['part_no']))
            ->groupBy('part_no');

        foreach ($grouped as $partNo => $partRows) {
            StockSnapshot::create([
                'part_no' => $partNo,
                'stock' => (int) $partRows->sum(fn (array $row) => (float) ($row['stock'] ?? 0)),
                'std_min' => (int) $partRows->sum(fn (array $row) => (float) ($row['std_min'] ?? 0)),
                'captured_at' => $capturedAt,
            ]);
        }

        $this->info("Captured stock snapshot for {$grouped->count()} parts (of {$wanted->count()} monitored) at {$capturedAt->toDateTimeString()}.");

        // Same piggyback as the Kesei notifier above: this is the only
        // moment new demand data exists, so it's also the only moment a Lot
        // Making 2 (demand-sourced) cycle could have just completed.
        try {
            $lotMakingPartNos = LotMaking::with('part')->get()
                ->map(fn (LotMaking $lotMaking) => $lotMaking->part?->part_no)
                ->filter()
                ->unique();

            foreach ($lotMakingPartNos as $partNo) {
                $demandCycles->checkForCompletion($partNo);
            }
        } catch (\Throwable $e) {
            Log::warning('LotMakingDemandCycleTracker failed during stock:capture-snapshot', ['error' => $e->getMessage()]);
        }

        return self::SUCCESS;
    }
}
