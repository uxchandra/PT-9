<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pattern_board_id', 'machine_id', 'part_id', 'proses'])]
class Pattern extends Model
{
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
     * The board+part master reference this assignment's loading_time,
     * jumlah_proses, total_kanban, dandori and display order come from.
     */
    public function groupItem(): ?PatternGroupItem
    {
        return PatternGroupItem::where('pattern_board_id', $this->pattern_board_id)
            ->where('part_id', $this->part_id)
            ->first();
    }
}
