<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReviewOperation extends Model
{
    protected $table = 'payment_review_operations';

    protected $guarded = [];

    protected $casts = ['result_json' => 'array'];
}
