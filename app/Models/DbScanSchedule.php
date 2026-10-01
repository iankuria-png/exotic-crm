<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanSchedule extends Model
{
    protected $table = 'db_scan_schedules';

    protected $guarded = [];

    protected $casts = [
        'market_scope' => 'array',
        'window' => 'array',
        'enabled' => 'boolean',
        'revision' => 'integer',
        'next_due_at' => 'datetime',
        'last_dispatched_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];
}
