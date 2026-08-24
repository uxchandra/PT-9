<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pattern_board_id', 'part_id', 'urutan', 'loading_time', 'jumlah_proses', 'total_kanban', 'dandori'])]
class PatternGroupItem extends Model
{
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
}
