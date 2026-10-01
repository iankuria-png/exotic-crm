<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanSurfaceCoverage extends Model
{
    protected $table = 'db_scan_surface_coverage';

    protected $guarded = [];

    protected $casts = [
        'completed_at' => 'datetime',
    ];
}
