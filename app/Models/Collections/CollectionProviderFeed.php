<?php

namespace App\Models\Collections;

use Illuminate\Database\Eloquent\Model;

class CollectionProviderFeed extends Model
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['enabled' => 'boolean', 'subscriptions' => 'array', 'cutover_at' => 'datetime', 'last_delivery_at' => 'datetime', 'reconciled_until' => 'datetime', 'last_poll_completed_at' => 'datetime', 'version' => 'integer'];

    public function account()
    {
        return $this->belongsTo(CollectionAccount::class, 'account_id');
    }
}
