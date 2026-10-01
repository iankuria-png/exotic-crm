<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanRuleOverride extends Model
{
    protected $table = 'db_scan_rule_overrides';

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
        'thresholds' => 'array',
        'disabled_lists' => 'array',
        'revision' => 'integer',
    ];
}
