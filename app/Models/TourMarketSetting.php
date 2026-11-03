<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TourMarketSetting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
        'revision' => 'integer',
        'synced_revision' => 'integer',
        'last_synced_at' => 'datetime',
    ];
}
