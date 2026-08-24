<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class PatternBoard extends Model
{
    public function groupItems(): HasMany
    {
        return $this->hasMany(PatternGroupItem::class)->orderBy('urutan');
    }

    public function patterns(): HasMany
    {
        return $this->hasMany(Pattern::class);
    }
}
