<?php

namespace App\Models\Collections;

use Illuminate\Database\Eloquent\Model;

class CollectionSalesItem extends Model
{
    protected $table = 'collection_sales_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $attributes = ['version' => 1, 'state' => 'draft'];

    protected $casts = ['purchase' => 'array', 'subsidiary' => 'encrypted:array', 'expected_site' => 'array', 'operation_result' => 'array', 'amount_minor' => 'integer', 'version' => 'integer', 'dispatch_started_at' => 'datetime', 'verified_at' => 'datetime'];

    protected $hidden = ['subsidiary', 'expected_site', 'operation_result'];

    public function session()
    {
        return $this->belongsTo(CollectionSalesSession::class, 'session_id');
    }

    public function client()
    {
        return $this->belongsTo(\App\Models\Client::class, 'client_id');
    }
}
