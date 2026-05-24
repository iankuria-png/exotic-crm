<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessagingSuppression extends Model
{
    protected $fillable = [
        'platform_id',
        'phone_e164',
        'email',
        'channel',
        'reason',
        'source_message_id',
        'opted_out_at',
        'revoked_at',
        'revoked_by',
        'notes',
    ];

    protected $casts = [
        'platform_id' => 'integer',
        'source_message_id' => 'integer',
        'opted_out_at' => 'datetime',
        'revoked_at' => 'datetime',
        'revoked_by' => 'integer',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function revokedBy()
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
