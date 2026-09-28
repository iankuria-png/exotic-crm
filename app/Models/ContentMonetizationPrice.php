<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentMonetizationPrice extends Model
{
    protected $guarded = [];

    protected $casts = ['price' => 'decimal:2', 'subsidy_value' => 'decimal:2', 'is_active' => 'boolean'];
}
