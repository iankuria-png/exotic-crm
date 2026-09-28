<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PremiumContentAsset extends Model
{
    protected $guarded = [];

    protected $casts = [];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
