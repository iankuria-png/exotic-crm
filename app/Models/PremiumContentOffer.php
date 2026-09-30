<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PremiumContentOffer extends Model
{
    protected $guarded = [];

    protected $casts = ['is_sandbox' => 'boolean', 'amount' => 'decimal:2', 'pricing_snapshot_json' => 'array', 'owner_opted_out_at' => 'datetime', 'published_at' => 'datetime'];

    public const ORIGIN_CREATOR = 'creator';

    public const ORIGIN_EXPIRY = 'expiry_automation';

    public const ORIGIN_ADMIN_BUNDLE = 'admin_bundle';

    public function isMultiCreator(): bool
    {
        return $this->bundle_scope === 'multi_creator';
    }

    public function assets()
    {
        return $this->belongsToMany(PremiumContentAsset::class, 'premium_content_offer_items', 'offer_id', 'asset_id')->withPivot('sort_order')->orderByPivot('sort_order');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
