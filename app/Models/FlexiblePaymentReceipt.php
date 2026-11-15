<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlexiblePaymentReceipt extends Model
{
    protected $guarded = [];

    protected $casts = ['phone' => 'encrypted', 'body' => 'encrypted', 'next_attempt_at' => 'datetime'];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
