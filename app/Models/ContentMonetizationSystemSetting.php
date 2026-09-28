<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentMonetizationSystemSetting extends Model
{
    protected $guarded = [];

    protected $casts = ['enabled' => 'boolean', 'activation_kill_switch' => 'boolean', 'checkout_kill_switch' => 'boolean'];
}
