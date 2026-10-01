<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanEvent extends Model
{
    protected $table = 'db_scan_events';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'context' => 'array',
        'at' => 'datetime',
    ];
}
