<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanRuleVersion extends Model
{
    protected $table = 'db_scan_rule_versions';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'definition' => 'array',
        'created_at' => 'datetime',
    ];
}
