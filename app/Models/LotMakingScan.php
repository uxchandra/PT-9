<?php

namespace App\Models;

use App\Services\LotMakingCycleTracker;
use App\Support\SosLabel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scanned SOS label against the Lot Making scanner card. Feeds both the
 * rolling stock-based demand (same math as Kesei's Finish Goods) and the
 * slot-fill tick position tracked by {@see LotMakingCycleTracker}.
 */
#[Fillable(['part_no', 'raw', 'scanned_by', 'scanned_at'])]
class LotMakingScan extends Model
{
    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
        ];
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }

    public static function parsePartNo(string $raw): ?string
    {
        return SosLabel::parsePartNo($raw);
    }
}
