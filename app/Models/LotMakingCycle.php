<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One completed Lot Making cycle — logged the instant a part's slot columns
 * all fill up (ticks since the previous completion reach lot_produksi).
 * Nothing about past ticks is touched; "since the last completion" is simply
 * the newest row here for that part_no + source. Powers the Andon Lot Making
 * roller panel.
 *
 * `source` keeps the two Andon boards' completions apart: SOURCE_SCAN for
 * "Lot Making 1" (ticks from real operator scans, see LotMakingCycleTracker)
 * and SOURCE_DEMAND for "Lot Making 2" (ticks straight from the SOS
 * kanban-pull/stock-decrease feed, see LotMakingDemandCycleTracker) — every
 * query against this table must filter by source, or the two boards' tallies
 * and rollers bleed into each other.
 */
#[Fillable(['part_no', 'lot_produksi', 'source', 'completed_at'])]
class LotMakingCycle extends Model
{
    public const SOURCE_SCAN = 'scan';

    public const SOURCE_DEMAND = 'demand';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }
}
