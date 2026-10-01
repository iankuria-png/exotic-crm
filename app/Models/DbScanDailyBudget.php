<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanDailyBudget extends Model
{
    protected $table = 'db_scan_daily_budgets';

    protected $guarded = [];

    protected $casts = [
        'active_seconds' => 'float',
    ];
}
