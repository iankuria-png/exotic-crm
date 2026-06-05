<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutoPushAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'auto_push_plan_id',
        'platform_id',
        'campaign_id',
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
        return $this->belongsTo(AutoPushPlan::class, 'auto_push_plan_id');
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function campaign()
    {
        return $this->belongsTo(PushCampaign::class, 'campaign_id');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
