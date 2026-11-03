<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TourOrderStop extends Model
{
    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'next_attempt_at' => 'datetime', 'verified_at' => 'datetime', 'refunded_at' => 'datetime', 'allocation_minor' => 'integer', 'revision' => 'integer'];

    public function order()
    {
        return $this->belongsTo(TourOrder::class, 'tour_order_id');
    }

    public function destination()
    {
        return $this->belongsTo(Platform::class, 'destination_platform_id');
    }
}
