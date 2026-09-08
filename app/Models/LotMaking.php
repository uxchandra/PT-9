<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'assy_part_code', 'part_id', 'qty_kanban', 'lot', 'loading_time', 'dandori',
    'lot_produksi', 'safety_stock', 'total_kanban_edar', 'next_process', 'kapasitas_rak',
])]
class LotMaking extends Model
{
    protected function casts(): array
    {
        return [
            'qty_kanban' => 'integer',
            'lot' => 'integer',
            'loading_time' => 'integer',
            'dandori' => 'integer',
            'lot_produksi' => 'integer',
            'safety_stock' => 'integer',
            'total_kanban_edar' => 'integer',
            'kapasitas_rak' => 'integer',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
