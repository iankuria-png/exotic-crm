<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanOccurrence extends Model
{
    protected $table = 'db_scan_occurrences';

    protected $guarded = [];

    protected $casts = [
        'due_at_utc' => 'datetime',
        'claimed_at' => 'datetime',
    ];
}
