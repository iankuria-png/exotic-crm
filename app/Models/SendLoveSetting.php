<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SendLoveSetting extends Model
{
    protected $guarded = [];

    protected $hidden = ['device_pepper'];

    protected $casts = ['enabled' => 'boolean', 'kill_switch' => 'boolean', 'presets_json' => 'array', 'allowed_providers_json' => 'array', 'copy_policy_json' => 'array', 'message_policy_json' => 'array', 'eligibility_json' => 'array', 'limits_json' => 'array', 'test_client_ids' => 'array', 'device_pepper' => 'encrypted', 'heartbeat_at' => 'datetime'];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }
}
