<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillingProviderTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'provider_type_key',
        'provider_profile_id',
        'normalized_status',
        'provider_transaction_id',
        'provider_session_id',
        'provider_invoice_id',
        'provider_status',
        'requested_amount',
        'requested_currency',
        'charge_amount',
        'charge_currency',
        'settled_amount',
        'settled_currency',
        'fee_amount',
        'fee_currency',
        'fx_rate',
        'fx_source',
        'fx_locked_at',
        'settlement_status',
        'expires_at',
        'confirmation_state_json',
        'upstream_reference_json',
        'attempt_group_key',
        'attempt_sequence',
        'retry_of_provider_transaction_id',
        'fallback_from_provider_transaction_id',
        'compatibility_reference',
        'state_version',
        'raw_state_json',
        'last_status_at',
    ];

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'charge_amount' => 'decimal:2',
        'settled_amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'fx_rate' => 'decimal:6',
        'expires_at' => 'datetime',
        'fx_locked_at' => 'datetime',
        'last_status_at' => 'datetime',
        'confirmation_state_json' => 'array',
        'upstream_reference_json' => 'array',
        'raw_state_json' => 'array',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function providerProfile()
    {
        return $this->belongsTo(BillingProviderProfile::class);
    }

    public function retryOf()
    {
        return $this->belongsTo(BillingProviderTransaction::class, 'retry_of_provider_transaction_id');
    }

    public function fallbackFrom()
    {
        return $this->belongsTo(BillingProviderTransaction::class, 'fallback_from_provider_transaction_id');
    }

    public function retries()
    {
        return $this->hasMany(BillingProviderTransaction::class, 'retry_of_provider_transaction_id');
    }

    public function fallbacks()
    {
        return $this->hasMany(BillingProviderTransaction::class, 'fallback_from_provider_transaction_id');
    }
}
