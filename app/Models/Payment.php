<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Payment extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'platform_id',
        'escort_post_id',
        'phone',
        'amount',
        'currency',
        'transaction_uuid',
        'transaction_reference',
        'status',
        'raw_payload',
        'duration',
        'start_date',
        'end_date'
    ];

    protected $casts = [
        'raw_payload' => 'array',
    ];
    
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    
    public static function hasActiveSubscription($userId)
    {
        return Payment::where('user_id', $userId)
            ->where('status', 'success')
            ->where('end_date', '>', now())
            ->exists();
    }
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }
}

