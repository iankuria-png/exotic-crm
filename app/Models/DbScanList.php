<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanList extends Model
{
    protected $table = 'db_scan_lists';

    protected $guarded = [];

    protected $casts = [
        'entries' => 'array',
        'revision' => 'integer',
    ];
}
