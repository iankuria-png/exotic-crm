<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutoPushPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'platform_id',
        'enabled',
        'autopilot',
        'buckets',
        'schedule',
        'message_strategy',
        'reliability',
        'created_by',
        'last_run_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'autopilot' => 'boolean',
        'buckets' => 'array',
        'schedule' => 'array',
        'message_strategy' => 'array',
        'reliability' => 'array',
        'last_run_at' => 'datetime',
    ];

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
        return $this->hasMany(AutoPushRun::class);
    }

    public function alerts()
    {
        return $this->hasMany(AutoPushAlert::class);
    }

    public function campaigns()
    {
        return $this->hasMany(PushCampaign::class);
    }

    public function scopeEnabledActive($query)
    {
        return $query->where('enabled', true)
            ->whereHas('platform', fn ($platformQuery) => $platformQuery->where('is_active', true));
    }
}
