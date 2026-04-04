<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillingRoutingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'market_id',
        'billing_surface',
        'primary_binding_id',
        'fallback_strategy_json',
        'risk_policy_json',
        'active',
    ];

    protected $casts = [
        'fallback_strategy_json' => 'array',
        'risk_policy_json' => 'array',
        'active' => 'boolean',
    ];

    public function market()
    {
        return $this->belongsTo(Platform::class, 'market_id');
    }

    public function platform()
    {
        return $this->market();
    }

    public function primaryBinding()
    {
        return $this->belongsTo(BillingMarketProviderBinding::class, 'primary_binding_id');
    }
}
