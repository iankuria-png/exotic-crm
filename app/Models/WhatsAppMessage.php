<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'platform_id',
        'direction',
        'engine',
        'provider_profile_id',
        'sender_id',
        'client_id',
        'lead_id',
        'deal_id',
        'payment_id',
        'template_id',
        'phone_e164',
        'body',
        'media_url',
        'provider_message_id',
        'idempotency_key',
        'status',
        'error_code',
        'error_message',
        'cost_micros',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
    ];

    protected $casts = [
        'platform_id' => 'integer',
        'provider_profile_id' => 'integer',
        'sender_id' => 'integer',
        'client_id' => 'integer',
        'lead_id' => 'integer',
        'deal_id' => 'integer',
        'payment_id' => 'integer',
        'template_id' => 'integer',
        'cost_micros' => 'integer',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function providerProfile()
    {
        return $this->belongsTo(WhatsAppProviderProfile::class, 'provider_profile_id');
    }

    public function sender()
    {
        return $this->belongsTo(WhatsAppSender::class, 'sender_id');
    }

    public function attempts()
    {
        return $this->hasMany(WhatsAppMessageAttempt::class, 'whatsapp_message_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function template()
    {
        return $this->belongsTo(Template::class);
    }
}
