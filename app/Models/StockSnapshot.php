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
     * Pattern, every Kesei row's part, and every Kesei stock-source part_no
     * (which may not exist in Part List at all). Both the capture command and
     * the Stock Snapshot page scope to this set.
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

        return $patternPartNos
            ->merge($keseiPartNos)
            ->merge($keseiSourceNos)
            ->filter()
            ->unique()
            ->values();
    }
}
