<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['part_no', 'stock', 'std_min', 'captured_at'])]
class StockSnapshot extends Model
{
    protected $casts = [
        'captured_at' => 'datetime',
    ];
}
