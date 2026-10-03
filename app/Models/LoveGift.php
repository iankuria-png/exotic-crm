<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoveGift extends Model
{
    protected $guarded = [];

    protected $hidden = ['contact_phone_encrypted', 'visitor_phone_hash', 'device_hash', 'idempotency_key_hash'];

    protected $casts = ['amount' => 'decimal:2', 'creator_credit_amount' => 'decimal:2', 'platform_share_amount' => 'decimal:2', 'provider_fee' => 'decimal:2', 'contact_shared' => 'boolean', 'contact_consent_at' => 'datetime', 'contact_phone_encrypted' => 'encrypted', 'is_sandbox' => 'boolean', 'sent_at' => 'datetime', 'seen_at' => 'datetime', 'metadata_json' => 'array'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
