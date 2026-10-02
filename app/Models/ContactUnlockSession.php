<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactUnlockSession extends Model
{
    public const SOURCE_PHONE_RESTORE = 'phone_restore';

    protected $fillable = [
        'visitor_contact_unlock_id',
        'platform_id',
        'session_token_hash',
        'public_token_hash',
        'source',
        'last_used_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    public function visitorUnlock()
    {
        return $this->belongsTo(VisitorContactUnlock::class, 'visitor_contact_unlock_id');
    }
}
