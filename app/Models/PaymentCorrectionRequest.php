<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentCorrectionRequest extends Model
{
    protected $table = 'payment_correction_requests';

    protected $guarded = [];

    protected $casts = ['required_fields' => 'array', 'response_revisions_json' => 'array', 'due_at' => 'datetime', 'version' => 'integer'];
}
