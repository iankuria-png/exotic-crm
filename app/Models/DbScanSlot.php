<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanSlot extends Model
{
    protected $table = 'db_scan_slots';

    protected $guarded = [];

    protected $casts = [
        'generation' => 'integer',
        'lease_expires_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'quarantined_at' => 'datetime',
    ];
}
