<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutoPushRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'auto_push_plan_id',
        'platform_id',
        'campaign_id',
        'window_start_at',
        'window_end_at',
        'bucket_counts',
        'candidates_selected',
        'reserve_count',
        'reserve_client_ids',
        'items_created',
        'replacements_made',
        'ai_cost_usd',
        'status',
        'error_message',
    ];

    protected $casts = [
        'window_start_at' => 'datetime',
        'window_end_at' => 'datetime',
        'bucket_counts' => 'array',
        'reserve_client_ids' => 'array',
        'candidates_selected' => 'integer',
        'reserve_count' => 'integer',
        'items_created' => 'integer',
        'replacements_made' => 'integer',
        'ai_cost_usd' => 'decimal:6',
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
}
