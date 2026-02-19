<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AfricanCountry extends Model
{
    use HasFactory;

    protected $table = 'african_countries';

    protected $fillable = [
        'name',
        'currency_name',
        'currency_code',
        'currency_symbol',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    /**
     * Scope a query to only include active countries.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get countries by currency code
     */
    public static function getByCurrencyCode($currencyCode)
    {
        return self::where('currency_code', $currencyCode)->get();
    }

    /**
     * Find country by name
     */
    public static function findByName($name)
    {
        return self::where('name', 'like', "%{$name}%")->first();
    }
}
