<?php

namespace App\Models\Collections;

use Illuminate\Database\Eloquent\Model;

class CollectionPurchaseIntent extends Model
{
    protected $table = 'collection_purchase_intents';

    protected $guarded = [];

    protected $attributes = ['version' => 1, 'used_count' => 0, 'max_renewals' => 1];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['version' => 'integer', 'used_count' => 'integer', 'max_renewals' => 'integer', 'purchase_snapshot' => 'array', 'approved_at' => 'datetime', 'valid_from' => 'datetime', 'valid_until' => 'datetime', 'revoked_at' => 'datetime'];

    protected $hidden = [];
}
