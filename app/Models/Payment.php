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
        'deal_id',
        'client_id',
        'match_confidence',
        'confirmed_by',
        'confirmed_at',
        'phone',
        'amount',
        'currency',
        'transaction_uuid',
        'transaction_reference',
        'status',
        'source',
        'import_batch_id',
        'import_legacy_hash',
        'raw_payload',
        'duration',
        'start_date',
        'end_date'
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'confirmed_at' => 'datetime',
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

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function attempts()
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function importBatch()
    {
        return $this->belongsTo(PaymentImportBatch::class, 'import_batch_id');
    }
}
