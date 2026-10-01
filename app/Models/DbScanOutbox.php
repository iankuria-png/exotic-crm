<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanOutbox extends Model
{
    protected $table = 'db_scan_outbox';

    protected $guarded = [];

    protected $casts = [
        'generation' => 'integer',
        'available_at' => 'datetime',
        'published_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
