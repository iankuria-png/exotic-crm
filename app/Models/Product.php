<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'name',
        'monthly_price',
        'biweekly_price',
        'weekly_price',
        'currency',
        'is_active',
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


   
    public function setMonthlyPriceAttribute($value)
    {
        $this->attributes['monthly_price'] = $value;
        $this->attributes['biweekly_price'] = $value / 2;
        $this->attributes['weekly_price'] = $value / 4;
    }
    public function platforms()
    {
        return $this->hasMany(Platform::class);
    }

}