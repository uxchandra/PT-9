<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['no', 'part_id', 'row', 'kolom', 'lot_produksi', 'slot'])]
class LotMaking extends Model
{
    protected function casts(): array
    {
        return [
            'no' => 'integer',
            'lot_produksi' => 'integer',
            'slot' => 'integer',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /**
     * lot_produksi ÷ slot, unrounded. Not stored — a pure function of the two
     * columns it derives from, so it can never go stale when either changes.
     * Null when slot isn't set (or zero), rather than dividing by it.
     */
    protected function avgSlot(): Attribute
    {
        return Attribute::get(
            fn () => ($this->slot && $this->lot_produksi !== null)
                ? $this->lot_produksi / $this->slot
                : null
        );
    }

    /**
     * avg_slot rounded UP to a whole slot (Excel's ROUNDUP) — how many slots
     * are actually needed to hold the production lot.
     */
    protected function slotFix(): Attribute
    {
        return Attribute::get(
            fn () => $this->avg_slot !== null ? (int) ceil($this->avg_slot) : null
        );
    }
}
