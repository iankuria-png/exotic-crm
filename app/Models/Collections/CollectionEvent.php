<?php

namespace App\Models\Collections;

use Illuminate\Database\Eloquent\Model;

class CollectionEvent extends Model
{
    protected $table = 'collection_events';

    protected $guarded = [];

    protected $casts = ['raw_body' => 'encrypted', 'normalized' => 'encrypted:array', 'sms_received_at' => 'datetime', 'ingested_at' => 'datetime'];

    protected $hidden = ['raw_body', 'normalized'];

    public $timestamps = false;
}
