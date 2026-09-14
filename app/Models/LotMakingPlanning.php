<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One completed Lot Making lot, thrown into a planning queue waiting to be
 * scheduled onto a machine's Andon timeline — see the migration for the full
 * mechanism. Three statuses, derived (not stored) from pattern_id/finished_at:
 *  - Open        — not yet assigned to a machine.
 *  - In Progress — assigned (a real `patterns` row exists, occupying an Andon slot).
 *  - Close       — finished; the `patterns` row is gone, kept here for history.
 */
#[Fillable(['part_id', 'lot', 'lot_making_cycle_id', 'pattern_board_id', 'machine_id', 'shift', 'proses', 'loading_time', 'pattern_id', 'finished_at'])]
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
            'shift' => 'integer',
            'proses' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    protected function status(): Attribute
    {
        return Attribute::get(fn () => match (true) {
            $this->isFinished() => self::STATUS_CLOSE,
            $this->isAssigned() => self::STATUS_IN_PROGRESS,
            default => self::STATUS_OPEN,
        });
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function lotMakingCycle(): BelongsTo
    {
        return $this->belongsTo(LotMakingCycle::class);
    }

    public function patternBoard(): BelongsTo
    {
        return $this->belongsTo(PatternBoard::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function pattern(): BelongsTo
    {
        return $this->belongsTo(Pattern::class);
    }

    public function isAssigned(): bool
    {
        return $this->pattern_id !== null;
    }

    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }
}
