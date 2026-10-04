<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RebateProgramRevision extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['snapshot_json' => 'array', 'diff_json' => 'array', 'created_at' => 'datetime'];
}
