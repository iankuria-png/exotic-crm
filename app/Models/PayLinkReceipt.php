<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayLinkReceipt extends Model
{
    protected $guarded = [];
    protected $hidden = ['phone', 'body'];
    protected $casts = ['phone' => 'encrypted', 'body' => 'encrypted', 'next_attempt_at' => 'datetime'];

    public function charge() { return $this->belongsTo(PayLinkCharge::class); }
}
