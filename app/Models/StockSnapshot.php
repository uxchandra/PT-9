<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

#[Fillable(['part_no', 'stock', 'std_min', 'captured_at'])]
class StockSnapshot extends Model
{
    protected $casts = [
        'captured_at' => 'datetime',
    ];

    /**
     * Every part_no worth recording / showing: parts assigned in any Kelompok
     * Pattern, every Kesei row's part, every Kesei stock-source part_no
     * (which may not exist in Part List at all), every Kesei/Lot Making row's
     * Material part_no (its RM — Andon Kesei's Closing Time panel needs this
     * one's stock for the Ready/Empty status), and every Lot Making part —
     * its scanner card and both Andon boards (Lot Making 1 and 2) all read
     * their demand straight from this snapshot history. Both the capture
     * command and the Stock Snapshot page scope to this set.
     *
     * @return Collection<int, string>
     */
    public static function monitoredPartNos(): Collection
    {
        $patternPartNos = Part::whereIn('id', PatternGroupItem::query()->select('part_id'))
            ->pluck('part_no');

        $keseiParts = KeseiPart::with('part')->get();
        $keseiPartNos = $keseiParts->map(fn (KeseiPart $kesei) => $kesei->part?->part_no);
        $keseiSourceNos = $keseiParts->flatMap(fn (KeseiPart $kesei) => $kesei->sourcePartNos());
        $keseiMaterialNos = $keseiParts->map(fn (KeseiPart $kesei) => $kesei->material_part_no);

        $lotMakings = LotMaking::with('part')->get();
        $lotMakingPartNos = $lotMakings->map(fn (LotMaking $lotMaking) => $lotMaking->part?->part_no);
        $lotMakingMaterialNos = $lotMakings->map(fn (LotMaking $lotMaking) => $lotMaking->material_part_no);

        return $patternPartNos
            ->merge($keseiPartNos)
            ->merge($keseiSourceNos)
            ->merge($keseiMaterialNos)
            ->merge($lotMakingPartNos)
            ->merge($lotMakingMaterialNos)
            ->filter()
            ->unique()
            ->values();
    }
}
