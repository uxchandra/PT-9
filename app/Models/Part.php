<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'part_no', 'part_no_fg', 'import_order', 'level', 'customer_code', 'model', 'job_no', 'part_name', 'type_box',
    'qty_kbn', 'process', 'line', 'line_code', 'rack_no', 'cap_rack', 'jig_no',
    'qty_lot', 'stock_min', 'stock_max', 'image_name', 'last_routing', 'remark', 'lt_pull',
    'lt_prod', 'code_partset', 'set_label', 'prod_point', 'cat_machine', 'spm',
    'dandory', 'cek_startfinish', 'update_by', 'update_time',
])]
class Part extends Model
{
    public function patterns(): HasMany
    {
        return $this->hasMany(Pattern::class);
    }
}
