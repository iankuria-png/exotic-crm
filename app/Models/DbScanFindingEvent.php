<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanFindingEvent extends Model
{
    protected $table = 'db_scan_finding_events';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
