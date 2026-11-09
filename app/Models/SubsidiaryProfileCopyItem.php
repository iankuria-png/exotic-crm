<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubsidiaryProfileCopyItem extends Model
{
    protected $table = 'subsidiary_profile_copy_items';

    protected $guarded = [];

    protected $casts = ['result_json' => 'array', 'completed_at' => 'datetime', 'attempts' => 'integer', 'position' => 'integer'];
}
