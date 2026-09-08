<?php

namespace App\Console\Commands;

use App\Models\StockSnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('stock:prune-snapshots')]
#[Description('Delete stock snapshots older than the retention window so the table does not grow unbounded')]
class PruneStockSnapshots extends Command
{
    private const RETENTION_DAYS = 7;

    public function handle(): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);
        $deleted = 0;

        // Chunked so a first run over a very large backlog never holds one
        // huge delete/lock.
        do {
            $batch = StockSnapshot::where('captured_at', '<', $cutoff)->limit(5000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info("Pruned {$deleted} stock snapshots older than {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
