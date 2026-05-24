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
            if ($deal->client_id) {
                $client = $deal->client()->with(['activeDeal.product', 'kycSubject'])->first();
                if ($client) {
                    app(\App\Services\Kyc\KycSettingsService::class)->recomputeClientRequirement($client);
                }
            }
        });

        static::deleted(function (Deal $deal): void {
            ClientRetentionInsightService::scheduleRefreshForClientId($deal->client_id ? (int) $deal->client_id : null);
            if ($deal->client_id) {
                $client = $deal->client()->with(['activeDeal.product', 'kycSubject'])->first();
                if ($client) {
                    app(\App\Services\Kyc\KycSettingsService::class)->recomputeClientRequirement($client);
                }
            }
        });
    }

    protected $fillable = [
        'platform_id', 'client_id', 'lead_id', 'payment_id',
        'product_id', 'product_price_id', 'base_product_price_id', 'plan_type', 'amount', 'currency',
        'duration', 'duration_days', 'status', 'activated_at', 'expires_at',
        'assigned_to', 'is_free_trial', 'free_trial_approved_by', 'payment_reference',
        'discount_percentage', 'original_amount', 'discount_approved_by', 'discount_source',
        'renewal_reminders_paused', 'renewal_paused_until', 'renewal_pause_reason',
        'origin', 'subscription_lifecycle', 'subscription_lifecycle_source', 'subscription_lifecycle_reason',
        'cancellation_reason_code', 'cancellation_notes', 'cancelled_payment_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'duration_days' => 'integer',
        'discount_percentage' => 'decimal:2',
        'original_amount' => 'decimal:2',
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

    public function cancelledPayment()
    {
        return $this->belongsTo(Payment::class, 'cancelled_payment_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productPrice()
    {
        return $this->belongsTo(ProductPrice::class);
    }

    public function baseProductPrice()
    {
        return $this->belongsTo(ProductPrice::class, 'base_product_price_id');
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
