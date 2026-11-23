<?php

namespace App\Models\Collections;

use Illuminate\Database\Eloquent\Model;

class CollectionProviderEvent extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['raw_body' => 'encrypted', 'observation' => 'encrypted:array', 'received_at' => 'datetime'];

    protected $hidden = ['raw_body', 'observation'];
}
