<?php

namespace App\Models\Collections;

use Illuminate\Database\Eloquent\Model;

class CollectionProviderResource extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['sender_hash' => 'encrypted'];

    protected $hidden = ['sender_hash'];
}
