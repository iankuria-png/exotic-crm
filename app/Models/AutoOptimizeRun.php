<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoOptimizeRun extends Model
{
    protected $fillable = [
        'auto_optimize_plan_id',
        'platform_id',
        'status',
        'batch_id',
        'candidates_scanned',
        'candidates_selected',
        'jobs_total',
        'jobs_completed',
        'jobs_failed',
        'items_applied',
        'ai_cost_usd',
        'error_message',
    ];

    protected $casts = [
        'candidates_scanned' => 'integer',
        'candidates_selected' => 'integer',
        'jobs_total' => 'integer',
        'jobs_completed' => 'integer',
        'jobs_failed' => 'integer',
        'items_applied' => 'integer',
        'ai_cost_usd' => 'decimal:6',
    ];

    public function plan()
    {
        return $this->belongsTo(AutoOptimizePlan::class, 'auto_optimize_plan_id');
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function items()
    {
        return $this->hasMany(AutoOptimizeItem::class, 'auto_optimize_run_id');
    }
}
