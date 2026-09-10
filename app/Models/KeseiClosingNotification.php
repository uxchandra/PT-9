<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['kesei_part_id', 'notified_on'])]
class KeseiClosingNotification extends Model
{
    public const UPDATED_AT = null;

    // 'notified_on' is left as a plain "Y-m-d" string — a 'date' cast would
    // serialise it back through the full datetime format on save (same reason
    // as CalendarEntry::$date).

    public function keseiPart(): BelongsTo
    {
        return $this->belongsTo(KeseiPart::class);
    }
}
