<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubsidiaryPromotionPolicy extends Model
{
    protected $table = 'subsidiary_promotion_policies';

    protected $guarded = [];

    protected $casts = ['fixed_days' => 'integer', 'allow_day_override' => 'boolean', 'copy_new_profile' => 'boolean', 'version' => 'integer'];
}
