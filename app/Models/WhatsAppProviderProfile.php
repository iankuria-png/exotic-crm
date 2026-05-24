<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppProviderProfile extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_provider_profiles';

    protected $fillable = [
        'market_id',
        'engine',
        'profile_name',
        'environment',
        'kill_switch_enabled',
        'meta_phone_number_id',
        'meta_business_account_id',
        'meta_access_token',
        'meta_webhook_verify_token',
        'meta_app_secret',
        'meta_api_version',
        'baileys_sidecar_url_override',
        'config_json',
        'active',
        'tested_at',
    ];

    protected $casts = [
        'kill_switch_enabled' => 'boolean',
        'meta_access_token' => 'encrypted',
        'meta_webhook_verify_token' => 'encrypted',
        'meta_app_secret' => 'encrypted',
        'config_json' => 'array',
        'active' => 'boolean',
        'tested_at' => 'datetime',
    ];

    public function market()
    {
        return $this->belongsTo(Platform::class, 'market_id');
    }

    public function senders()
    {
        return $this->hasMany(WhatsAppSender::class, 'provider_profile_id');
    }

    public function routingRules()
    {
        return $this->hasMany(WhatsAppRoutingRule::class, 'primary_profile_id');
    }

    public function messages()
    {
        return $this->hasMany(WhatsAppMessage::class, 'provider_profile_id');
    }

    public function apiVersion(): string
    {
        return $this->meta_api_version ?: (string) config('services.whatsapp.meta_default_api_version', 'v25.0');
    }
}
