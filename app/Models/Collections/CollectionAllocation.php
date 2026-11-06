<?php

namespace App\Models\Collections;

use Illuminate\Database\Eloquent\Model;

class CollectionAllocation extends Model
{
    protected $table = 'collection_allocations';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['version' => 'integer', 'purchase_snapshot' => 'array'];

    protected $hidden = [];

    public function receipt()
    {
        return $this->belongsTo(CollectionReceipt::class, 'receipt_id');
    }

    public function effects()
    {
        return $this->hasMany(CollectionBenefitEffect::class, 'allocation_id');
    }

    public function payment()
    {
        return $this->belongsTo(\App\Models\Payment::class);
    }
}
