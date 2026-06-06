<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoOptimizeAlert extends Model
{
    protected $fillable = [
        'auto_optimize_plan_id',
        'platform_id',
        'client_id',
        'severity',
        'type',
        'title',
        'body',
        'context',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'context' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function plan()
    {
        return $this->belongsTo(AutoOptimizePlan::class, 'auto_optimize_plan_id');
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
