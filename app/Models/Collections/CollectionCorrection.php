<?php

namespace App\Models\Collections;

use Illuminate\Database\Eloquent\Model;

class CollectionCorrection extends Model
{
    protected $table = 'collection_corrections';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['version' => 'integer', 'preview' => 'array', 'expires_at' => 'datetime'];

    protected $hidden = [];
}
