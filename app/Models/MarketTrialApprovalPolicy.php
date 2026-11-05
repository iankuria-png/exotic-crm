<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketTrialApprovalPolicy extends Model
{
    protected $table = 'market_trial_approval_policies';

    protected $guarded = [];

    protected $primaryKey = 'platform_id';

    public $incrementing = false;

    protected $casts = ['version' => 'integer'];
}
