<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactUnlockUpgradeCredit extends Model
{
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_RELEASED = 'released';
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'upgrade_unlock_id',
        'source_unlock_id',
        'source_payment_id',
        'platform_id',
        'currency',
        'credited_amount',
        'status',
        'applied_at',
        'metadata_json',
    ];

    protected $casts = [
        'credited_amount' => 'decimal:2',
        'applied_at' => 'datetime',
        'metadata_json' => 'array',
    ];
}
