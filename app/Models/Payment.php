<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Payment extends Model
{
    use HasFactory;

    public const SUCCESSFUL_STATUSES = ['completed'];
    public const ACTIVE_SUBSCRIPTION_STATUSES = ['completed', 'activated'];

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
        'reference_number',
        'status',
        'purpose',
        'failure_reason',
        'completed_at',
        'source',
        'wallet_transaction_id',
        'provider_key',
        'provider_environment',
        'import_batch_id',
        'import_legacy_hash',
        'reconciliation_confidence',
        'reconciliation_state',
        'raw_payload',
        'payment_data',
        'duration',
        'start_date',
        'end_date'
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'payment_data' => 'array',
        'confirmed_at' => 'datetime',
        'completed_at' => 'datetime',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];
    
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    
    public static function hasActiveSubscription($userId)
    {
        return Payment::where('user_id', $userId)
            ->whereIn('status', self::ACTIVE_SUBSCRIPTION_STATUSES)
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

    public function walletTransaction()
    {
        return $this->belongsTo(WalletTransaction::class);
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
