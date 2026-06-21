<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeoBoostBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_id',
        'created_by',
        'product_id',
        'product_price_id',
        'plan_type',
        'duration_days',
        'borrow_mode',
        'status',
        'target_count',
        'selected_count',
        'activated_count',
        'failed_count',
        'expired_count',
        'activated_at',
        'completed_at',
        'notes',
        'settings',
    ];

    protected $casts = [
        'duration_days' => 'integer',
        'target_count' => 'integer',
        'selected_count' => 'integer',
        'activated_count' => 'integer',
        'failed_count' => 'integer',
        'expired_count' => 'integer',
        'activated_at' => 'datetime',
        'completed_at' => 'datetime',
        'settings' => 'array',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productPrice()
    {
        return $this->belongsTo(ProductPrice::class);
    }

    public function targets()
    {
        return $this->hasMany(SeoBoostTarget::class, 'batch_id');
    }

    public function items()
    {
        return $this->hasMany(SeoBoostItem::class, 'batch_id');
    }
}
