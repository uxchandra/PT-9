<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One closing-time definition for a Kesei part. A part can carry several of
 * these (e.g. 05:00 and 15:00) — each folds the pile and notifies on its own,
 * independently of the others. See KeseiPart::foldBoundaries().
 */
#[Fillable(['kesei_part_id', 'closing_time', 'closing_mode'])]
class KeseiPartClosing extends Model
{
    protected function casts(): array
    {
        return [
            'closing_time' => 'datetime:H:i',
        ];
    }

    public function keseiPart(): BelongsTo
    {
        return $this->belongsTo(KeseiPart::class);
    }

    public function isPreRunClosing(): bool
    {
        return $this->closing_mode !== KeseiPart::CLOSING_END_OF_DAY;
    }
}
