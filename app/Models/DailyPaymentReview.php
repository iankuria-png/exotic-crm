<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyPaymentReview extends Model
{
    protected $table = 'daily_payment_reviews';

    protected $guarded = [];

    protected $casts = ['platform_ids_json' => 'array', 'snapshot_json' => 'array', 'flagged_at' => 'datetime', 'decided_at' => 'datetime', 'self_approval' => 'boolean', 'version' => 'integer'];
}
