<?php

namespace App\Services;

use App\Models\LotMaking;
use App\Models\LotMakingCycle;
use App\Models\LotMakingScan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the Andon Lot Making board: parts grouped into rack "row" bands,
 * ordered left-to-right within a band by "kolom", each showing its slot
 * breakdown — `slot` columns, the first (slot - 1) each holding slot_fix,
 * the last holding whatever's left so the columns sum to exactly
 * lot_produksi (Excel's ROUNDUP behaviour: only the last slot is partial) —
 * plus how many of each column's capacity is filled so far ("ticks").
 *
 * A part with no `row` set doesn't get lumped in with every other unrowed
 * part — each renders as its own single-part band.
 *
 * Two flavours, identical grid, different tick source — same idea as
 * KeseiBoard's 'stock'/'scan' split:
 *  - data('scan')   — "Lot Making 1": ticks from real operator scans
 *  - data('demand') — "Lot Making 2": ticks straight from the SOS
 *    kanban-pull/stock-decrease feed, no scan required
 *
 * The right-hand roller panel lists completed cycles for that same source
 * (see LotMakingCycle), newest at the bottom.
 */
class LotMakingBoard
{
    private const ROLLER_LIMIT = 30;

    public function __construct(private LotMakingPull $pull)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function data(string $source = 'scan'): array
    {
        // `row`/`kolom` are free-text (not necessarily numeric-padded or even
        // sequential), so ordering is done in PHP with a natural comparison —
        // "10" must sort after "2", not before it. `no` plays no part in
        // where a band lands: it's a free-form per-part label (see
        // LotMakingController's listing for its one actual ordering role) and
        // is explicitly allowed to be set in any order.
        $lotMakings = LotMaking::with('part')->orderBy('id')->get();

        $partNos = $lotMakings->map(fn (LotMaking $lm) => $lm->part?->part_no)->filter()->unique()->values()->all();
        $cycleSource = $source === 'demand' ? LotMakingCycle::SOURCE_DEMAND : LotMakingCycle::SOURCE_SCAN;
        $ticksByPart = $source === 'demand'
            ? $this->ticksSinceLastCycleDemand($partNos)
            : $this->ticksSinceLastCycleScan($partNos);

        $rows = $lotMakings
            ->groupBy(fn (LotMaking $lm) => $lm->row !== null ? 'row:'.$lm->row : 'solo:'.$lm->id)
            ->map(fn (Collection $parts) => [
                'row' => $parts->first()->row,
                'parts' => $parts
                    ->sortBy(fn (LotMaking $lm) => $lm->kolom, SORT_NATURAL | SORT_FLAG_CASE)
                    ->map(fn (LotMaking $lm) => $this->partBlock($lm, $ticksByPart))
                    ->values()
                    ->all(),
            ])
            ->sort(function (array $a, array $b) {
                // Bands with no row set always sort after every numbered one.
                if ($a['row'] === null || $b['row'] === null) {
                    return $a['row'] === $b['row'] ? 0 : ($a['row'] === null ? 1 : -1);
                }

                return strnatcasecmp($a['row'], $b['row']);
            })
            ->values()
            ->all();

        // Oldest first — rendered top-to-bottom, so the newest completion
        // naturally ends up at the bottom of the panel. Scoped to this
        // board's own source so a scan completion never shows up on the
        // demand board's roller or vice versa.
        $cycles = LotMakingCycle::where('source', $cycleSource)
            ->orderByDesc('completed_at')
            ->limit(self::ROLLER_LIMIT)
            ->get()
            ->sortBy('completed_at')
            ->values();

        return ['rows' => $rows, 'cycles' => $cycles];
    }

    /**
     * @param  array<int, int>  $ticksByPart
     * @return array{no: ?int, part_no: string, lot_produksi: ?int, slots: array<int, int>, ticks: array<int, int>}
     */
    private function partBlock(LotMaking $lm, array $ticksByPart): array
    {
        $partNo = $lm->part?->part_no;
        $slots = $this->slotValues($lm);
        $ticks = $partNo !== null ? ($ticksByPart[$partNo] ?? 0) : 0;

        return [
            'no' => $lm->no,
            'part_no' => $partNo ?? '(part terhapus)',
            'lot_produksi' => $lm->lot_produksi,
            'slots' => $slots,
            // Same left-to-right fill as the slots themselves, capped per
            // column — how many of each column's capacity are already ticked.
            'ticks' => $this->distribute($ticks, $slots),
        ];
    }

    /**
     * Scans recorded since each part's last completed *scan* cycle (or ever,
     * if it has never completed one) — batched across every part in one pass
     * rather than per-row queries.
     *
     * @param  array<int, string>  $partNos
     * @return array<string, int>
     */
    private function ticksSinceLastCycleScan(array $partNos): array
    {
        if ($partNos === []) {
            return [];
        }

        $lastCompletion = LotMakingCycle::whereIn('part_no', $partNos)
            ->where('source', LotMakingCycle::SOURCE_SCAN)
            ->selectRaw('part_no, MAX(completed_at) as completed_at')
            ->groupBy('part_no')
            ->pluck('completed_at', 'part_no');

        $scansByPart = LotMakingScan::whereIn('part_no', $partNos)
            ->orderBy('scanned_at')
            ->get(['part_no', 'scanned_at'])
            ->groupBy('part_no');

        $ticks = [];

        foreach ($partNos as $partNo) {
            $since = $lastCompletion->get($partNo);
            $scans = $scansByPart->get($partNo, collect());

            $ticks[$partNo] = $since !== null
                ? $scans->filter(fn (LotMakingScan $s) => $s->scanned_at->gt($since))->count()
                : $scans->count();
        }

        return $ticks;
    }

    /**
     * Kanban pulled (stock decrease from the SOS feed) since each part's last
     * completed *demand* cycle — batched across every part in one pass via
     * LotMakingPull::eventsSinceBatch(), rather than one stock-snapshot query
     * per part.
     *
     * eventsSinceBatch()'s cutoff is inclusive, so the boundary event (the
     * one a completion was last logged against) comes back in full — a
     * single stock-snapshot tick can carry more than one lot's worth of
     * kanban, and whatever didn't form a full lot needs to keep showing up
     * as pending ticks rather than vanishing. So this nets out exactly how
     * much of that boundary event was already spent (see
     * LotMakingDemandCycleTracker, which does the matching math when logging
     * completions) before returning the remainder.
     *
     * @param  array<int, string>  $partNos
     * @return array<string, int>
     */
    private function ticksSinceLastCycleDemand(array $partNos): array
    {
        if ($partNos === []) {
            return [];
        }

        $cycles = LotMakingCycle::whereIn('part_no', $partNos)
            ->where('source', LotMakingCycle::SOURCE_DEMAND)
            ->get(['part_no', 'completed_at']);

        $lastCompletion = $cycles->groupBy('part_no')
            ->map(fn (Collection $rows) => $rows->max('completed_at'));

        $completionsAtBoundary = $cycles->groupBy('part_no')
            ->map(fn (Collection $rows, string $partNo) => $rows
                ->where('completed_at', $lastCompletion->get($partNo))
                ->count());

        $lotProduksiByPart = LotMaking::with('part')->get()
            ->filter(fn (LotMaking $lm) => $lm->part !== null && in_array($lm->part->part_no, $partNos, true))
            ->keyBy(fn (LotMaking $lm) => $lm->part->part_no)
            ->map(fn (LotMaking $lm) => $lm->lot_produksi ?? 0);

        $sinceByPart = collect($partNos)
            ->mapWithKeys(fn (string $partNo) => [
                $partNo => $lastCompletion->has($partNo) ? Carbon::parse($lastCompletion->get($partNo)) : null,
            ])
            ->all();

        $eventsByPart = $this->pull->eventsSinceBatch($sinceByPart);

        $ticks = [];

        foreach ($partNos as $partNo) {
            $pulled = (int) ($eventsByPart[$partNo] ?? collect())->sum('kanban');
            $alreadySpent = ($completionsAtBoundary->get($partNo) ?? 0) * ($lotProduksiByPart->get($partNo) ?? 0);
            $ticks[$partNo] = max(0, $pulled - $alreadySpent);
        }

        return $ticks;
    }

    /**
     * Spread $count across $capacities left to right, each column capped at
     * its own capacity — the same shape as slotValues(), just for "how much
     * of this column is filled" instead of "how much this column holds".
     *
     * @param  array<int, int>  $capacities
     * @return array<int, int>
     */
    private function distribute(int $count, array $capacities): array
    {
        $remaining = $count;
        $result = [];

        foreach ($capacities as $capacity) {
            $take = min($capacity, $remaining);
            $result[] = $take;
            $remaining -= $take;
        }

        return $result;
    }

    /**
     * `slot` columns, filled left to right up to slot_fix (ROUNDUP of
     * lot_produksi / slot) each — the last column takes whatever's left over.
     * Filling left-to-right (rather than always giving every column but the
     * last the full slot_fix) matters when slot is large relative to
     * lot_produksi: with e.g. lot_produksi=5 and slot=8, slot_fix=1, so
     * columns 1-5 get 1 each and columns 6-8 get 0 — never negative, which a
     * flat "slot_fix everywhere but the last" formula could produce there.
     * Empty when lot_produksi or slot isn't set yet (nothing to break down).
     *
     * @return array<int, int>
     */
    private function slotValues(LotMaking $lm): array
    {
        $slot = $lm->slot;
        $lotProduksi = $lm->lot_produksi;
        $fix = $lm->slot_fix;

        if ($slot === null || $slot < 1 || $lotProduksi === null || $fix === null) {
            return [];
        }

        $remaining = $lotProduksi;
        $values = [];

        for ($i = 0; $i < $slot - 1; $i++) {
            $take = min($fix, $remaining);
            $values[] = $take;
            $remaining -= $take;
        }

        $values[] = $remaining;

        return $values;
    }
}
