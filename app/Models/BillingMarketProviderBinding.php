<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillingMarketProviderBinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'market_id',
        'provider_profile_id',
        'billing_surface',
        'enabled',
        'operator_enabled',
        'self_service_enabled',
        'execution_mode',
        'priority',
        'fallback_group',
        'restriction_json',
        'notes',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'operator_enabled' => 'boolean',
        'self_service_enabled' => 'boolean',
        'priority' => 'integer',
        'restriction_json' => 'array',
    ];

    public function market()
    {
        return $this->belongsTo(Platform::class, 'market_id');
    }

    public function platform()
    {
        return $this->market();
    }

    public function providerProfile()
    {
        return $this->belongsTo(BillingProviderProfile::class, 'provider_profile_id');
    }

    public function primaryRoutingRules()
    {
        return $this->hasMany(BillingRoutingRule::class, 'primary_binding_id');
    }

    public function chosenRoutingDecisions()
    {
        return $this->hasMany(BillingRoutingDecision::class, 'chosen_binding_id');
    }
}
