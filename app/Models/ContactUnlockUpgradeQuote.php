<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactUnlockUpgradeQuote extends Model
{
    protected $fillable = [
        'platform_id',
        'pricing_rule_id',
        'quote_token_hash',
        'session_token_hash',
        'visitor_phone_hash',
        'currency',
        'full_access_amount',
        'eligible_credit',
        'amount_due',
        'credit_window_days',
        'credit_sources_json',
        'expires_at',
    ];

    protected $casts = [
        'full_access_amount' => 'decimal:2',
        'eligible_credit' => 'decimal:2',
        'amount_due' => 'decimal:2',
        'credit_window_days' => 'integer',
        'credit_sources_json' => 'array',
        'expires_at' => 'datetime',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function pricingRule()
    {
        return $this->belongsTo(ContactUnlockPricingRule::class, 'pricing_rule_id');
    }
}
