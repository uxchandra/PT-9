<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A part's fixed Heijunka Box release schedule — see the migration that
 * creates this table for the full picture.
 */
#[Fillable(['part_id', 'cycle_issue', 'sort_order', 'slots'])]
class HeijunkaBoxSchedule extends Model
{
    protected function casts(): array
    {
        return [
            'slots' => 'array',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
