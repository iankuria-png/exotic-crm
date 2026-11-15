<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayLinkSetting extends Model
{
    protected $guarded = [];
    protected $hidden = ['test_device_hashes'];
    protected $casts = ['test_client_ids' => 'array', 'test_device_hashes' => 'array', 'offers_json' => 'array', 'provider_order' => 'array', 'limits_json' => 'array', 'switch_package_while_live' => 'boolean'];

    public function platform() { return $this->belongsTo(Platform::class); }
}
