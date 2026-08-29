<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pattern_board_id', 'part_id', 'shift', 'urutan', 'lot', 'loading_time', 'jumlah_proses', 'total_kanban', 'dandori'])]
class PatternGroupItem extends Model
{
    public const SHIFT_LABELS = [
        1 => 'Shift 1 (07:00–16:00)',
        2 => 'Shift 2 (20:00–06:00)',
    ];

    public function patternBoard(): BelongsTo
    {
        return $this->belongsTo(PatternBoard::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function loadingTimePerPattern(): int
    {
        return $this->loading_time * $this->jumlah_proses;
    }

    /**
     * total_kanban is derived, not entered manually: lot divided by the part's
     * Qty Kbn (Part List), rounded up. Returns 0 if the part has no usable
     * (numeric, > 0) Qty Kbn set yet.
     */
    public static function calculateTotalKanban(int $lot, ?string $qtyKbn): int
    {
        $qty = is_numeric($qtyKbn) ? (float) $qtyKbn : 0.0;

        if ($qty <= 0) {
            return 0;
        }

        return (int) ceil($lot / $qty);
    }
}
