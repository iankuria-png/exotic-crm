<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FreeTrialRequest extends Model
{
    protected $table = 'free_trial_requests';

    protected $guarded = [];

    protected $casts = ['terms_json' => 'array', 'activation_result_json' => 'array', 'authorized_at' => 'datetime', 'expires_at' => 'datetime', 'version' => 'integer', 'policy_version' => 'integer', 'pin_generation' => 'integer'];
}
