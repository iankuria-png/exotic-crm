<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    protected $fillable = [
        'phone',
        'message',
        'status',
        'response',
        'payment_id',
        'briefing_recipient_id',
        'sent_at',
        'result_code',
        'provider',
        'platform_id',
        'http_code',
        'purpose',
        'fallback_used',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'http_code' => 'integer',
        'fallback_used' => 'boolean',
    ];

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }
}
