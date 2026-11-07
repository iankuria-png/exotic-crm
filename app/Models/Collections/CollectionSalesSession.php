<?php

namespace App\Models\Collections;

use Illuminate\Database\Eloquent\Model;

class CollectionSalesSession extends Model
{
    protected $table = 'collection_sales_sessions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $attributes = ['version' => 1, 'state' => 'draft'];

    protected $casts = ['version' => 'integer', 'receipt_version' => 'integer', 'principal_minor' => 'integer', 'allocated_minor' => 'integer', 'remainder_minor' => 'integer', 'confirmed_at' => 'datetime'];

    public function items()
    {
        return $this->hasMany(CollectionSalesItem::class, 'session_id')->orderBy('position');
    }

    public function receipt()
    {
        return $this->belongsTo(CollectionReceipt::class, 'receipt_id');
    }
}
