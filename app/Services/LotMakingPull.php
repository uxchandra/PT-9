<?php

namespace App\Services;

use App\Models\LotMaking;
use App\Models\LotMakingScan;
use App\Models\PatternGroupItem;
use App\Models\StockSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The "pulling command" for the Lot Making scanner card — same rolling
 * 15-minute stock-based target/scanned/remaining math as Kesei's Finish
 * Goods card (see KeseiPull::demandRow). Lot Making has no closing time, so
 * there's nothing to fold: the accumulation window is simply a fixed
 * history floor, and it never resets on its own (that's an entirely
 * separate concept — see LotMakingCycleTracker for the slot-fill/roller
 * side of scanning).
 */
class LotMakingPull
{
    private const HISTORY_DAYS = 8;

    /**
     * @return Collection<int, array{part_no: string, needed: int, scanned: int, remaining: int, last_update: ?string, done: bool}>
     */
    public function list(): Collection
    {
        $now = now();
        $floor = $now->copy()->subDays(self::HISTORY_DAYS);

        $lotMakings = LotMaking::with('part')->get()->filter(fn (LotMaking $lm) => $lm->part?->part_no !== null);
        $partNos = $lotMakings->map(fn (LotMaking $lm) => $lm->part->part_no)->unique()->values()->all();

        if ($partNos === []) {
            return collect();
        }

        $snapshotsByPart = StockSnapshot::whereIn('part_no', $partNos)
            ->whereBetween('captured_at', [$floor->copy()->subDay(), $now])
            ->orderBy('captured_at')
            ->get(['part_no', 'stock', 'captured_at'])
            ->groupBy('part_no');

        $scansByPart = LotMakingScan::whereIn('part_no', $partNos)
            ->where('scanned_at', '>', $floor)
            ->orderBy('scanned_at')
            ->get(['part_no', 'scanned_at'])
            ->groupBy('part_no');

        return $lotMakings
            ->map(fn (LotMaking $lm) => $this->demandRow(
                $lm->part->part_no,
                $lm->part->qty_kbn,
                $floor,
                $snapshotsByPart->get($lm->part->part_no, collect()),
                $scansByPart->get($lm->part->part_no, collect())->pluck('scanned_at')
            ))
            ->filter(fn (array $r) => $r['needed'] > 0 || $r['scanned'] > 0)
            ->sortBy('done')
            ->values();
    }

    /**
     * One row's figures computed on its own (no full-list rebuild) so a scan
     * stays fast — the same reasoning as KeseiPull::scanRow.
     *
     * @return array{part_no: string, needed: int, scanned: int, remaining: int, last_update: ?string, done: bool}|null
     */
    public function scanRow(string $partNo): ?array
    {
        $lm = LotMaking::with('part')
            ->whereHas('part', fn ($q) => $q->where('part_no', $partNo))
            ->first();

        if ($lm === null || $lm->part === null) {
            return null;
        }

        $now = now();
        $floor = $now->copy()->subDays(self::HISTORY_DAYS);

        $snapshots = StockSnapshot::where('part_no', $partNo)
            ->whereBetween('captured_at', [$floor->copy()->subDay(), $now])
            ->orderBy('captured_at')
            ->get(['part_no', 'stock', 'captured_at']);

        $scanTimes = LotMakingScan::where('part_no', $partNo)
            ->where('scanned_at', '>', $floor)
            ->orderBy('scanned_at')
            ->pluck('scanned_at');

        return $this->demandRow($partNo, $lm->part->qty_kbn, $floor, $snapshots, $scanTimes);
    }

    /**
     * @param  Collection<int, StockSnapshot>  $snapshots  chronological, may include seed rows before $from
     * @param  Collection<int, Carbon>  $scanTimes
     * @return array{part_no: string, needed: int, scanned: int, remaining: int, last_update: ?string, done: bool}
     */
    private function demandRow(string $partNo, ?string $qtyKbn, Carbon $from, Collection $snapshots, Collection $scanTimes): array
    {
        $events = $this->decreaseEvents($qtyKbn, $from, $snapshots);
        $totalDecrease = (int) $events->sum('kanban');
        $lastUpdateAt = $events->pluck('at')->max();

        if ($lastUpdateAt !== null) {
            $absorbed = $scanTimes->filter(fn (Carbon $t) => $t->lte($lastUpdateAt))->count();
            $scanned = $scanTimes->filter(fn (Carbon $t) => $t->gt($lastUpdateAt))->count();
        } else {
            $absorbed = 0;
            $scanned = $scanTimes->count();
        }

        $needed = max(0, $totalDecrease - $absorbed);

        return [
            'part_no' => $partNo,
            'needed' => $needed,
            'scanned' => $scanned,
            'remaining' => max(0, $needed - $scanned),
            'last_update' => $lastUpdateAt?->format('H:i'),
            'done' => $needed > 0 && $scanned >= $needed,
        ];
    }

    /**
     * @param  Collection<int, StockSnapshot>  $snapshots
     * @return Collection<int, array{kanban: int, at: Carbon}>
     */
    private function decreaseEvents(?string $qtyKbn, Carbon $from, Collection $snapshots): Collection
    {
        $events = collect();
        $previous = null;

        foreach ($snapshots as $row) {
            $stock = (int) $row->stock;

            if ($row->captured_at->lt($from)) {
                $previous = $stock; // seed only — primes $previous, never an event itself.

                continue;
            }

            $decreasePcs = $previous !== null ? $previous - $stock : 0;

            if ($decreasePcs > 0) {
                $kanban = PatternGroupItem::calculateTotalKanban($decreasePcs, $qtyKbn);

                if ($kanban > 0) {
                    $events->push(['kanban' => $kanban, 'at' => $row->captured_at]);
                }
            }

            $previous = $stock;
        }

        return $events;
    }
}
