<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PremiumContentOffer extends Model
{
    protected $guarded = [];

    protected $casts = ['is_sandbox' => 'boolean', 'amount' => 'decimal:2'];

    public function assets()
    {
        return $this->belongsToMany(PremiumContentAsset::class, 'premium_content_offer_items', 'offer_id', 'asset_id')->withPivot('sort_order')->orderByPivot('sort_order');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
