<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'duration_key',
        'duration_label',
        'duration_days',
        'price',
        'currency',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'duration_days' => 'integer',
        'price' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
