<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpDailyUsage extends Model
{
    protected $guarded = [];

    protected $casts = ['usage_date' => 'date'];
}
