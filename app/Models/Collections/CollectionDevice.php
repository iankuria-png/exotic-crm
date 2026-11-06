<?php

namespace App\Models\Collections;

use Illuminate\Database\Eloquent\Model;

class CollectionDevice extends Model
{
    protected $table = 'collection_devices';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['signing_secret' => 'encrypted', 'inbox_cursor' => 'encrypted', 'acceptance' => 'array', 'revoked_at' => 'datetime', 'cutover_at' => 'datetime', 'last_seen_at' => 'datetime'];

    protected $hidden = ['signing_secret', 'inbox_cursor'];
}
