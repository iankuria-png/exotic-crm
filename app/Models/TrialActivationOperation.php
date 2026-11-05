<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrialActivationOperation extends Model
{
    protected $table = 'trial_activation_operations';

    protected $guarded = [];

    protected $casts = ['command_json' => 'array', 'baseline_json' => 'array', 'response_json' => 'array', 'verified_effect_json' => 'array', 'dispatched_at' => 'datetime', 'verified_at' => 'datetime', 'finalized_at' => 'datetime', 'version' => 'integer'];
}
