<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlexibleCheckoutAttempt extends Model
{
    protected $guarded = [];

    protected $casts = ['action' => 'array', 'dispatch_started_at' => 'datetime'];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
