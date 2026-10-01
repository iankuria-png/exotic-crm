<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanObservation extends Model
{
    protected $table = 'db_scan_observations';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'evidence' => 'array',
        'created_at' => 'datetime',
    ];
}
