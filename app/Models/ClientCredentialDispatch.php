<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientCredentialDispatch extends Model
{
    protected $fillable = [
        'client_id',
        'platform_id',
        'method',
        'channel',
        'timing',
        'status',
        'recipient_email',
        'recipient_phone',
        'error_message',
        'payload',
        'provider_results',
        'created_by',
        'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'provider_results' => 'array',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
