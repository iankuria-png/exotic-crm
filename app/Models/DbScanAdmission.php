<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanAdmission extends Model
{
    protected $table = 'db_scan_admissions';

    protected $guarded = [];

    protected $casts = [
        'generation' => 'integer',
    ];
}
