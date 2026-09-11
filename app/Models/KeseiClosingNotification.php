<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['kesei_part_id', 'notified_on', 'qty_kbn'])]
class KeseiClosingNotification extends Model
{
    public const UPDATED_AT = null;

    // 'notified_on' is the exact closing instant (a part can carry more than
    // one closing time a day, so the date alone isn't enough to dedupe them)
    // and is left as a plain "Y-m-d H:i:s" string — a 'datetime' cast would
    // serialise it back through a different format on save (same reason as
    // CalendarEntry::$date).

    public function keseiPart(): BelongsTo
    {
        return $this->belongsTo(KeseiPart::class);
    }
}
