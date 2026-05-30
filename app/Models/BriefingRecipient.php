<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BriefingRecipient extends Model
{
    protected $fillable = [
        'briefing_id',
        'user_id',
        'name',
        'phone',
        'audience',
        'scope_platform_ids',
        'share_token',
        'expires_at',
        'sms_text',
        'sms_char_count',
        'sms_segments',
        'delivery_status',
        'sms_log_id',
        'opened_at',
        'opt_out_snapshot',
    ];

    protected $casts = [
        'scope_platform_ids' => 'array',
        'expires_at' => 'datetime',
        'opened_at' => 'datetime',
        'opt_out_snapshot' => 'boolean',
        'sms_char_count' => 'integer',
        'sms_segments' => 'integer',
    ];

    public function briefing(): BelongsTo
    {
        return $this->belongsTo(Briefing::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
