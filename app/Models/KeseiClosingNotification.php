<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['kesei_part_id', 'notified_on'])]
class KeseiClosingNotification extends Model
{
    public const UPDATED_AT = null;
}
