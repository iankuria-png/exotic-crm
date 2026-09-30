<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitorContentPurchase extends Model
{
    protected $guarded = [];

    protected $casts = ['entitlement_snapshot_json' => 'array', 'metadata_json' => 'array', 'is_sandbox' => 'boolean', 'purchased_at' => 'datetime', 'gross_amount' => 'decimal:2', 'provider_fee' => 'decimal:2', 'creator_credit_amount' => 'decimal:2'];

    protected $hidden = ['visitor_phone_hash', 'first_device_hash', 'public_token_hash', 'idempotency_key_hash'];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function allocations()
    {
        return $this->hasMany(VisitorContentPurchaseAllocation::class, 'purchase_id');
    }

    public function offer()
    {
        return $this->belongsTo(PremiumContentOffer::class);
    }
}
