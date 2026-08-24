<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ContactUnlockPricingRule extends Model
{
    public const SCOPE_SINGLE_PROFILE = 'single_profile';
    public const SCOPE_MARKET_INACTIVE_PROFILES = 'market_inactive_profiles';

    protected $fillable = [
        'platform_id',
        'scope',
        'label',
        'currency',
        'amount',
        'duration_days',
        'is_active',
        'provider_policy_json',
        'rate_limit_json',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'duration_days' => 'integer',
        'is_active' => 'boolean',
        'provider_policy_json' => 'array',
        'rate_limit_json' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function scopeActiveForPlatform(Builder $query, int $platformId): Builder
    {
        return $query
            ->where('platform_id', $platformId)
            ->where('is_active', true)
            ->where(function (Builder $builder): void {
                $builder->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function (Builder $builder): void {
                $builder->whereNull('ends_at')->orWhere('ends_at', '>', now());
            });
    }
}
