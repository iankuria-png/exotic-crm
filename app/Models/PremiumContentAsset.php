<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PremiumContentAsset extends Model
{
    protected $guarded = [];

    protected $casts = ['teaser_generated_at' => 'datetime'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
