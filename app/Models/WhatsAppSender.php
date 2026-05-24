<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppSender extends Model
{
    protected $table = 'whatsapp_senders';

    protected $fillable = [
        'provider_profile_id',
        'phone_e164',
    ];

    public function providerProfile()
    {
        return $this->belongsTo(WhatsAppProviderProfile::class, 'provider_profile_id');
    }
}
