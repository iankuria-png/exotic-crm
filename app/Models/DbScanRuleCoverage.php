<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanRuleCoverage extends Model
{
    protected $table = 'db_scan_rule_coverage';

    protected $guarded = [];

    protected $casts = [
        'rows' => 'integer',
        'matches' => 'integer',
    ];
}
