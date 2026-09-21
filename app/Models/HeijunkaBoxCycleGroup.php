<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per "cycle issue" pitch group on the Heijunka Box board (e.g.
 * "1-2-X") — the sheet's own fixed per-slot random numbers and the group's
 * display order. See the grouping import migration for where this comes
 * from and HeijunkaBoxBoard for how it's rendered.
 */
#[Fillable(['cycle_issue', 'sort_order', 'random_numbers'])]
class HeijunkaBoxCycleGroup extends Model
{
    protected function casts(): array
    {
        return [
            'random_numbers' => 'array',
        ];
    }
}
