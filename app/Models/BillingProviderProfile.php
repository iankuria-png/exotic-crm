<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillingProviderProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_type_key',
        'profile_name',
        'country_code',
        'market_id',
        'merchant_scope_json',
        'environment',
        'config_json',
        'secrets_json',
        'active',
    ];

    protected $casts = [
        'merchant_scope_json' => 'array',
        'config_json' => 'array',
        'secrets_json' => 'array',
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

    public function bindings()
    {
        return $this->hasMany(BillingMarketProviderBinding::class, 'provider_profile_id');
    }

    public function routingDecisions()
    {
        return $this->hasMany(BillingRoutingDecision::class, 'provider_profile_id');
    }
}
