<?php

namespace App\Models\Collections;

use Illuminate\Database\Eloquent\Model;

class CollectionSalesCorrection extends Model
{
    protected $table = 'collection_sales_corrections';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['preview' => 'array', 'compensation_result' => 'array', 'target_result' => 'array',
        'expires_at' => 'datetime', 'version' => 'integer', 'item_version' => 'integer', 'generation' => 'integer'];
}
