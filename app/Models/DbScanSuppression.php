<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanSuppression extends Model
{
    protected $table = 'db_scan_suppressions';

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
