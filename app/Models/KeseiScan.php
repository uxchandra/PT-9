<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One scanned SOS label = one kanban pull. These are the red ticks on the
 * scan-driven Andon Kesei board (as opposed to the API-stock-driven one).
 */
#[Fillable(['part_no', 'location', 'raw', 'scanned_by', 'scanned_at'])]
class KeseiScan extends Model
{
    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
        ];
    }

    /**
     * Pull the part number out of an SOS QR payload like
     * "S9 09 I 26 A_8_57183-BZ010_1" — the 3rd underscore-separated segment.
     * Returns null when the payload doesn't have that shape.
     */
    public static function parsePartNo(string $raw): ?string
    {
        $segments = explode('_', trim($raw));

        $partNo = trim($segments[2] ?? '');

        return $partNo !== '' ? $partNo : null;
    }
}
