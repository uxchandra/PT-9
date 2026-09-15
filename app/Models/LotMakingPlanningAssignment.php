<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One proses step of a LotMakingPlanning lot, assigned to a machine and a
 * shift — see the migration for the full mechanism. Shift is picked per
 * step, not once for the whole lot: two steps of the same lot can genuinely
 * run on different shifts. Closes independently of its siblings too: a part
 * "splits" into its jumlah_proses steps and each one runs its own course
 * (Open -> In Progress -> Close).
 */
#[Fillable(['lot_making_planning_id', 'proses', 'machine_id', 'shift', 'pattern_id', 'finished_at'])]
class LotMakingPlanningAssignment extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'proses' => 'integer',
            'shift' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    public function planning(): BelongsTo
    {
        return $this->belongsTo(LotMakingPlanning::class, 'lot_making_planning_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function pattern(): BelongsTo
    {
        return $this->belongsTo(Pattern::class);
    }

    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }
}
