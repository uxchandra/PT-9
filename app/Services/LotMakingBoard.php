<?php

namespace App\Services;

use App\Models\LotMaking;
use App\Models\LotMakingCycle;
use App\Models\LotMakingScan;
use Illuminate\Support\Collection;

/**
 * Builds the Andon Lot Making board: parts grouped into rack "row" bands,
 * ordered left-to-right within a band by "kolom", each showing its slot
 * breakdown — `slot` columns, the first (slot - 1) each holding slot_fix,
 * the last holding whatever's left so the columns sum to exactly
 * lot_produksi (Excel's ROUNDUP behaviour: only the last slot is partial) —
 * plus how many of each column's capacity has been scanned so far ("ticks").
 *
 * A part with no `row` set doesn't get lumped in with every other unrowed
 * part — each renders as its own single-part band.
 *
 * The right-hand roller panel lists completed cycles (see LotMakingCycle),
 * newest at the bottom.
 */
class LotMakingBoard
{
    private const ROLLER_LIMIT = 30;

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        // `row`/`kolom` are free-text (not necessarily numeric-padded or even
        // sequential), so ordering is done in PHP with a natural comparison —
        // "10" must sort after "2", not before it. `no` plays no part in
        // where a band lands: it's a free-form per-part label (see
        // LotMakingController's listing for its one actual ordering role) and
        // is explicitly allowed to be set in any order.
        $lotMakings = LotMaking::with('part')->orderBy('id')->get();

        $partNos = $lotMakings->map(fn (LotMaking $lm) => $lm->part?->part_no)->filter()->unique()->values()->all();
        $ticksByPart = $this->ticksSinceLastCycle($partNos);

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
        // naturally ends up at the bottom of the panel.
        $cycles = LotMakingCycle::orderByDesc('completed_at')
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
     * Scans recorded since each part's last completed cycle (or ever, if it
     * has never completed one) — batched across every part in one pass
     * rather than per-row queries.
     *
     * @param  array<int, string>  $partNos
     * @return array<string, int>
     */
    private function ticksSinceLastCycle(array $partNos): array
    {
        if ($partNos === []) {
            return [];
        }

        $lastCompletion = LotMakingCycle::whereIn('part_no', $partNos)
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
