<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EscortLiveUrl extends Model
{
    protected $table = 'escort_live_urls';
    protected $primaryKey = 'post_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'post_id',
        'post_name',
        'live_url',
        'last_synced'
    ];

    protected $casts = [
        'post_id' => 'integer',
        'last_synced' => 'datetime'
    ];
}