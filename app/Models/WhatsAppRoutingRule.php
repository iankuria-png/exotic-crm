<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppRoutingRule extends Model
{
    protected $table = 'whatsapp_routing_rules';

    protected $fillable = [
        'market_id',
        'message_type',
        'primary_profile_id',
        'fallback_profile_id',
        'fallback_to_sms',
        'enabled',
    ];

    protected $casts = [
        'fallback_to_sms' => 'boolean',
        'enabled' => 'boolean',
    ];

    public function market()
    {
        return $this->belongsTo(Platform::class, 'market_id');
    }

    public function primaryProfile()
    {
        return $this->belongsTo(WhatsAppProviderProfile::class, 'primary_profile_id');
    }

    public function fallbackProfile()
    {
        return $this->belongsTo(WhatsAppProviderProfile::class, 'fallback_profile_id');
    }
}
