<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TourPaymentAttempt extends Model
{
    protected $guarded = [];

    protected $casts = ['action' => 'array', 'initiated_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(TourOrder::class, 'tour_order_id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
