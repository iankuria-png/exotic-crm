<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class VisitorContactUnlock extends Model
{
    public const SCOPE_SINGLE_PROFILE = 'single_profile';
    public const SCOPE_MARKET_INACTIVE_PROFILES = 'market_inactive_profiles';

    public const STATUS_INITIATED = 'initiated';
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'platform_id',
        'client_id',
        'wp_post_id',
        'payment_id',
        'pricing_rule_id',
        'scope',
        'status',
        'visitor_phone_hash',
        'visitor_phone_masked',
        'visitor_email_hash',
        'visitor_email_masked',
        'idempotency_key_hash',
        'session_token_hash',
        'public_token_hash',
        'starts_at',
        'expires_at',
        'last_revealed_at',
        'reveal_count',
        'metadata_json',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_revealed_at' => 'datetime',
        'reveal_count' => 'integer',
        'metadata_json' => 'array',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function pricingRule()
    {
        return $this->belongsTo(ContactUnlockPricingRule::class, 'pricing_rule_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where(function (Builder $builder): void {
                $builder->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function (Builder $builder): void {
                $builder->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function isActive(): bool
    {
        return (string) $this->status === self::STATUS_ACTIVE
            && ($this->starts_at === null || $this->starts_at->lte(now()))
            && ($this->expires_at === null || $this->expires_at->gt(now()));
    }
}
