<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessagingWebhookEvent extends Model
{
    protected $fillable = [
        'engine',
        'external_event_id',
        'received_at',
        'payload_hash',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];
}
