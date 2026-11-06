<?php

namespace App\Models\Collections;

use Illuminate\Database\Eloquent\Model;

class CollectionReceipt extends Model
{
    protected $table = 'collection_receipts';

    protected $guarded = [];

    protected $attributes = ['version' => 1, 'funds_state' => 'observed', 'decision_state' => 'collecting', 'evidence_level' => 'phone'];

    protected $casts = ['version' => 'integer', 'payer_phone' => 'encrypted', 'historical_only' => 'boolean', 'principal_minor' => 'integer', 'field_provenance' => 'array', 'conflicts' => 'array', 'candidate_snapshot' => 'array', 'provider_at' => 'datetime', 'provider_latest_at' => 'datetime', 'first_received_at' => 'datetime', 'last_received_at' => 'datetime', 'enrichment_deadline' => 'datetime', 'reviewed_at' => 'datetime'];

    protected $hidden = ['payer_phone', 'payer_fingerprint'];

    public function account()
    {
        return $this->belongsTo(CollectionAccount::class, 'account_id');
    }

    public function allocation()
    {
        return $this->hasOne(CollectionAllocation::class, 'receipt_id');
    }
}
