<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanRule extends Model
{
    protected $table = 'db_scan_rules';

    protected $guarded = [];

    protected $casts = [
        'surfaces' => 'array',
        'definition' => 'array',
        'profiles' => 'array',
        'references' => 'array',
        'allowlistable' => 'boolean',
        'enabled' => 'boolean',
        'retired' => 'boolean',
    ];
}
