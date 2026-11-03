<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TourOrder extends Model
{
    protected $guarded = [];

    protected $casts = ['snapshot' => 'array', 'quote' => 'array', 'quote_expires_at' => 'datetime', 'pass_expires_at' => 'datetime', 'paid_at' => 'datetime', 'cancelled_at' => 'datetime', 'amount_minor' => 'integer', 'config_revision' => 'integer', 'winning_attempt_id' => 'integer'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function attempts()
    {
        return $this->hasMany(TourPaymentAttempt::class);
    }

    public function stops()
    {
        return $this->hasMany(TourOrderStop::class);
    }
}
