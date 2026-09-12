<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One completed Lot Making cycle — logged the instant a part's slot columns
 * all fill up (scanned count since the previous completion reaches
 * lot_produksi). Nothing about past scans is touched; "since the last
 * completion" is simply the newest row here for that part_no. Powers the
 * Andon Lot Making roller panel.
 */
#[Fillable(['part_no', 'lot_produksi', 'completed_at'])]
class LotMakingCycle extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }
}
