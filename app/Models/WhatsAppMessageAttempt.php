<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMessageAttempt extends Model
{
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'whatsapp_message_attempts';

    protected $fillable = [
        'whatsapp_message_id',
        'attempt_number',
        'engine',
        'provider_profile_id',
        'sender_id',
        'attempt_uuid',
        'status',
        'error_code',
        'error_message',
        'latency_ms',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'whatsapp_message_id' => 'integer',
        'attempt_number' => 'integer',
        'provider_profile_id' => 'integer',
        'sender_id' => 'integer',
        'latency_ms' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function message()
    {
        return $this->belongsTo(WhatsAppMessage::class, 'whatsapp_message_id');
    }

    public function providerProfile()
    {
        return $this->belongsTo(WhatsAppProviderProfile::class, 'provider_profile_id');
    }

    public function sender()
    {
        return $this->belongsTo(WhatsAppSender::class, 'sender_id');
    }
}
