<?php

namespace App\Models\Collections;

use Illuminate\Database\Eloquent\Model;

class CollectionPayerLink extends Model
{
    protected $table = 'collection_payer_links';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['payer_phone' => 'encrypted', 'auto_allowed' => 'boolean', 'evidence_receipt_ids' => 'array', 'reviewed_at' => 'datetime', 'valid_until' => 'datetime', 'revoked_at' => 'datetime'];

    protected $hidden = ['payer_phone', 'payer_fingerprint'];
}
