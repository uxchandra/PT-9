<?php

namespace App\Models;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['no', 'part_id', 'level', 'pulling_command', 'pulling_command_set_at', 'row', 'kolom', 'lot_produksi', 'slot', 'loading_time', 'dandori', 'jumlah_proses', 'material_part_no', 'material_level', 'lt_per_kbn', 'cycles', 'order_per_cycle'])]
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

    /**
     * The fixed set of production cycles a part can be assigned to (C1..C10).
     */
    public const CYCLES = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

    protected function casts(): array
    {
        return [
            'no' => 'integer',
            'lot_produksi' => 'integer',
            'slot' => 'integer',
            'loading_time' => 'integer',
            'dandori' => 'integer',
            'jumlah_proses' => 'integer',
            'order_per_cycle' => 'integer',
            'pulling_command_set_at' => 'datetime',
            'cycles' => 'array',
            // Lead Time per Kanban (minutes) — decimal, not whole minutes
            // (e.g. 20.8) — same attribute as KeseiPart::lt_per_kbn.
            'lt_per_kbn' => 'float',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /**
     * Free-text search across row/kolom/part_no plus level (matched by its
     * human label — "Finish Goods", "Store 3" — since that's what shows in
     * the UI, not the stored code). Shared by the index page and Export so
     * "export the data currently shown" stays honest to what's on screen.
     */
    public function scopeSearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $matchingLevels = collect(self::LEVELS)
            ->filter(fn ($label) => stripos($label, $search) !== false)
            ->keys()
            ->all();

        $query->where(function (Builder $q) use ($search, $matchingLevels) {
            $q->where('row', 'like', "%{$search}%")
                ->orWhere('kolom', 'like', "%{$search}%")
                ->orWhereHas('part', fn (Builder $p) => $p->where('part_no', 'like', "%{$search}%"));

            if ($matchingLevels !== []) {
                $q->orWhereIn('level', $matchingLevels);
            }
        });
    }

    /**
     * The "H:i" time set for the given cycle number (1-10), or null when
     * that cycle has no time set for this row. Stored as {cycle: "H:i"}.
     */
    public function cycleTime(int $cycle): ?string
    {
        return $this->cycles[$cycle] ?? null;
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
