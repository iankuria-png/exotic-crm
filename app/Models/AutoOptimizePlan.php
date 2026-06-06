<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoOptimizePlan extends Model
{
    protected $fillable = [
        'name',
        'platform_id',
        'enabled',
        'autopilot',
        'criteria',
        'actions',
        'schedule',
        'reliability',
        'created_by',
        'last_run_at',
        'enabled_platform_key',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'autopilot' => 'boolean',
        'criteria' => 'array',
        'actions' => 'array',
        'schedule' => 'array',
        'reliability' => 'array',
        'last_run_at' => 'datetime',
    ];

    // Driver-safe one-enabled-plan-per-market guard.
    // enabled_platform_key = platform_id when enabled, NULL otherwise.
    // The unique index on enabled_platform_key (NULLs allowed) enforces the constraint.
    protected static function booted(): void
    {
        static::saving(function (self $plan) {
            $plan->enabled_platform_key = $plan->enabled ? (int) $plan->platform_id : null;
        });
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function runs()
    {
        return $this->hasMany(AutoOptimizeRun::class);
    }

    public function items()
    {
        return $this->hasMany(AutoOptimizeItem::class);
    }

    public function alerts()
    {
        return $this->hasMany(AutoOptimizeAlert::class);
    }

    public function scopeEnabledActive($query)
    {
        return $query->where('enabled', true)
            ->whereHas('platform', fn ($q) => $q->where('is_active', true));
    }
}
