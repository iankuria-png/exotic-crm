<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_id',
        'name',
        'display_name',
        'slug',
        'tier',
        'monthly_price',
        'biweekly_price',
        'weekly_price',
        'currency',
        'is_active',
        'is_archived',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_archived' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function getMonthlyPriceWithCurrencyAttribute()
    {
        return $this->currency . ' ' . number_format($this->monthly_price, 2);
    }

    public function getBiweeklyPriceWithCurrencyAttribute()
    {
        return $this->currency . ' ' . number_format($this->biweekly_price, 2);
    }

    public function getWeeklyPriceWithCurrencyAttribute()
    {
        return $this->currency . ' ' . number_format($this->weekly_price, 2);
    }

    // Note: Legacy auto-calculation mutator removed. With dynamic product_prices,
    // weekly/biweekly/monthly are synced independently via syncLegacyPriceColumnsForProduct().

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function prices()
    {
        return $this->hasMany(ProductPrice::class)->orderBy('sort_order')->orderBy('id');
    }

    public function activePrices()
    {
        return $this->hasMany(ProductPrice::class)
            ->where('is_active', true)
            ->where('price', '>', 0)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
