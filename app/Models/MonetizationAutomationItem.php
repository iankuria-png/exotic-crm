<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonetizationAutomationItem extends Model
{
    protected $guarded = [];

    protected $casts = ['rule_json' => 'array', 'result_json' => 'array'];

    public function run()
    {
        return $this->belongsTo(MonetizationAutomationRun::class, 'run_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
