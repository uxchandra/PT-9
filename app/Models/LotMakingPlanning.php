<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One completed Lot Making lot, thrown into a planning queue waiting to be
 * scheduled onto a machine's Andon timeline — see the migration for the full
 * mechanism. A lot goes through `jumlah_proses` steps (see
 * lot_makings.jumlah_proses), each independently assignable to its own
 * machine AND shift AND independently closable (see
 * LotMakingPlanningAssignment) — two steps of the same lot can genuinely run
 * on different shifts, so shift is picked per step, not once for the lot.
 *
 * There's no single status for the lot itself — "Open"/"In Progress"/"Close"
 * (see STATUS_LABELS) describe one proses STEP, not the lot as a whole: a
 * lot with 3 steps can have one Open, one In Progress and one Close all at
 * once, each shown on its own tab (see LotMakingPlanningController::index).
 */
#[Fillable(['part_id', 'lot', 'lot_making_cycle_id'])]
class LotMakingPlanning extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_CLOSE = 'close';

    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'Open',
        self::STATUS_IN_PROGRESS => 'In Progress',
        self::STATUS_CLOSE => 'Close',
    ];

    protected function casts(): array
    {
        return [
            'lot' => 'integer',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function lotMakingCycle(): BelongsTo
    {
        return $this->belongsTo(LotMakingCycle::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LotMakingPlanningAssignment::class);
    }

    public function isAssigned(): bool
    {
        return $this->relationLoaded('assignments')
            ? $this->assignments->isNotEmpty()
            : $this->assignments()->exists();
    }
}
