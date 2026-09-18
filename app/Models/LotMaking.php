<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['no', 'part_id', 'level', 'pulling_command', 'pulling_command_set_at', 'row', 'kolom', 'lot_produksi', 'slot', 'loading_time', 'dandori', 'jumlah_proses', 'material_part_no', 'material_level'])]
class LotMaking extends Model
{
    /**
     * Which scanner card (see KeseiPull::LOCATIONS) this part's pulling
     * command is grouped under, and therefore its mode: 'finish-goods' is
     * rolling stock-based demand (capped), 'store-3' is free/unlimited —
     * see LotMakingPull.
     */
    public const LEVEL_FINISH_GOODS = 'finish-goods';

    public const LEVEL_STORE_3 = 'store-3';

    public const LEVELS = [
        self::LEVEL_FINISH_GOODS => 'Finish Goods',
        self::LEVEL_STORE_3 => 'Store 3',
    ];

    protected function casts(): array
    {
        return [
            'no' => 'integer',
            'lot_produksi' => 'integer',
            'slot' => 'integer',
            'loading_time' => 'integer',
            'dandori' => 'integer',
            'jumlah_proses' => 'integer',
            'pulling_command_set_at' => 'datetime',
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
