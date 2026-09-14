<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pattern_board_id', 'machine_id', 'part_id', 'shift', 'proses', 'loading_time', 'jumlah_proses', 'dandori', 'total_kanban'])]
class Pattern extends Model
{
    public const SHIFT_LABELS = [
        1 => 'Shift 1 (07:00–16:00)',
        2 => 'Shift 2 (20:00–06:00)',
    ];

    public function patternBoard(): BelongsTo
    {
        return $this->belongsTo(PatternBoard::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /**
     * The board+part+shift master reference this assignment's loading_time,
     * jumlah_proses, total_kanban, dandori and display order come from — for
     * a normal (Kelompok Pattern-backed) assignment. A Lot Making Planning
     * assignment has no such row on purpose (see the patterns table
     * migration) and carries this same data on itself instead.
     */
    public function groupItem(): ?PatternGroupItem
    {
        return PatternGroupItem::where('pattern_board_id', $this->pattern_board_id)
            ->where('part_id', $this->part_id)
            ->where('shift', $this->shift)
            ->first();
    }
}
