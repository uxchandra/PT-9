<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pattern_id', 'produced_on', 'actual_kanban', 'kanban_override'])]
class PatternActual extends Model
{
    // produced_on is deliberately NOT cast to 'date': Eloquent's date casts
    // still serialize through the connection's full datetime format on save
    // (e.g. "2026-08-31 00:00:00"), which would make plain string matching
    // against a "Y-m-d" value silently miss. Always read/write it as the
    // plain "Y-m-d" string (Carbon::toDateString()) instead.

    public function pattern(): BelongsTo
    {
        return $this->belongsTo(Pattern::class);
    }
}
