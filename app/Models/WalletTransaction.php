<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'platform_id',
        'type',
        'currency_code',
        'amount',
        'balance_after',
        'reference_type',
        'reference_id',
        'payment_id',
        'deal_id',
        'idempotency_key',
        'description',
        'performed_by',
        'metadata',
        'notification_channel',
        'notification_sent_at',
        'wp_synced_at',
        'wp_sync_attempts',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'metadata' => 'array',
        'notification_sent_at' => 'datetime',
        'wp_synced_at' => 'datetime',
        'wp_sync_attempts' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
