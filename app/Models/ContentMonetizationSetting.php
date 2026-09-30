<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentMonetizationSetting extends Model
{
    protected $guarded = [];

    protected $casts = ['enabled' => 'boolean', 'activation_kill_switch' => 'boolean', 'checkout_kill_switch' => 'boolean', 'offer_policy_json' => 'array', 'surface_policy_json' => 'array', 'checkout_policy_json' => 'array', 'delivery_policy_json' => 'array', 'test_client_ids' => 'array', 'readiness_json' => 'array', 'expiry_video_policy_json' => 'array', 'free_pass_policy_json' => 'array', 'heartbeat_at' => 'datetime', 'grant_secret' => 'encrypted', 'device_pepper' => 'encrypted'];

    protected $hidden = ['grant_secret', 'device_pepper'];

    public function prices()
    {
        return $this->hasMany(ContentMonetizationPrice::class, 'setting_id');
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }
}
