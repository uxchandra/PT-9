<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Assignment Machine" — master data naming which machine runs a given
 * process step of a Lot Making part (one row per part+proses). See the
 * migration for the full reasoning; Lot Making Planning requires a matching
 * row here before that part can be assigned to a machine.
 */
#[Fillable(['part_id', 'machine_id', 'proses'])]
class LotMakingAssignment extends Model
{
    protected function casts(): array
    {
        return [
            'proses' => 'integer',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
