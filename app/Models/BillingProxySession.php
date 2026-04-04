<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillingProxySession extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'billing_routing_decision_id',
        'provider_profile_id',
        'provider_type_key',
        'environment',
        'token_hash',
        'token_expires_at',
        'redirect_url',
        'provider_reference',
        'opened_at',
        'open_count',
        'initialized_at',
        'callback_at',
        'rotation_count',
        'state',
        'legacy_meta_json',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'opened_at' => 'datetime',
        'initialized_at' => 'datetime',
        'callback_at' => 'datetime',
        'legacy_meta_json' => 'array',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function routingDecision()
    {
        return $this->belongsTo(BillingRoutingDecision::class, 'billing_routing_decision_id');
    }

    public function providerProfile()
    {
        return $this->belongsTo(BillingProviderProfile::class);
    }
}
