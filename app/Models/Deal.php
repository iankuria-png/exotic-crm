<?php

namespace App\Models;

use App\Services\ClientRetentionInsightService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Deal extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(function (Deal $deal): void {
            ClientRetentionInsightService::scheduleRefreshForClientId($deal->client_id ? (int) $deal->client_id : null);
        });

        static::deleted(function (Deal $deal): void {
            ClientRetentionInsightService::scheduleRefreshForClientId($deal->client_id ? (int) $deal->client_id : null);
        });
    }

    protected $fillable = [
        'platform_id', 'client_id', 'lead_id', 'payment_id',
        'product_id', 'plan_type', 'amount', 'currency',
        'duration', 'status', 'activated_at', 'expires_at',
        'assigned_to', 'is_free_trial', 'free_trial_approved_by', 'payment_reference',
        'renewal_reminders_paused', 'renewal_paused_until', 'renewal_pause_reason',
        'origin',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_free_trial' => 'boolean',
        'renewal_reminders_paused' => 'boolean',
        'renewal_paused_until' => 'datetime',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function assignedAgent()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function timelineEvents()
    {
        return $this->morphMany(TimelineEvent::class, 'entity', 'entity_type', 'entity_id')
            ->where('entity_type', 'deal');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeExpiringSoon($query, $days = 7)
    {
        return $query->where('status', 'active')
            ->where('expires_at', '<=', now()->addDays($days))
            ->where('expires_at', '>', now());
    }

    public function scopeForPlatform($query, $platformId)
    {
        return $query->where('platform_id', $platformId);
    }
}
