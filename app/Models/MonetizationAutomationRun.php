<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonetizationAutomationRun extends Model
{
    protected $guarded = [];

    protected $casts = ['rule_json' => 'array', 'finished_at' => 'datetime'];

    public function items()
    {
        return $this->hasMany(MonetizationAutomationItem::class, 'run_id');
    }
}
