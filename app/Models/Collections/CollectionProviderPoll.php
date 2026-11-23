<?php

namespace App\Models\Collections;

use Illuminate\Database\Eloquent\Model;

class CollectionProviderPoll extends Model
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['from_at' => 'datetime', 'until_at' => 'datetime', 'historical_only' => 'boolean', 'pending_result' => 'encrypted'];

    protected $hidden = ['pending_result'];
}
