<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class Machine extends Model
{
    public function patterns(): HasMany
    {
        return $this->hasMany(Pattern::class);
    }
}
