<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanChunk extends Model
{
    protected $table = 'db_scan_chunks';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'committed_at' => 'datetime',
    ];
}
