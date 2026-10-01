<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanSnapshot extends Model
{
    protected $table = 'db_scan_snapshots';

    protected $guarded = [];

    protected $casts = [
        'components' => 'array',
        'taken_at' => 'datetime',
    ];
}
