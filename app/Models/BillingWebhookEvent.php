<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillingWebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_type_key',
        'provider_profile_id',
        'market_id',
        'provider_event_id',
        'dedupe_key',
        'headers_json',
        'raw_body',
        'payload_json',
        'signature_status',
        'verification_meta_json',
        'processing_status',
        'retry_count',
        'last_error',
        'billing_provider_transaction_id',
        'payment_id',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'headers_json' => 'array',
        'payload_json' => 'array',
        'verification_meta_json' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function providerProfile()
    {
        return $this->belongsTo(BillingProviderProfile::class);
    }

    public function market()
    {
        return $this->belongsTo(Platform::class, 'market_id');
    }

    public function providerTransaction()
    {
        return $this->belongsTo(BillingProviderTransaction::class, 'billing_provider_transaction_id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
