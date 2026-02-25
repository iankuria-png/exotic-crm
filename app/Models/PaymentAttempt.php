<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentAttempt extends Model
{
    protected $fillable = [
        'payment_id',
        'attempt_type',
        'provider',
        'status',
        'error_code',
        'error_message',
        'http_status',
        'latency_ms',
        'request_meta',
        'response_meta',
        'created_by',
    ];

    protected $casts = [
        'request_meta' => 'array',
        'response_meta' => 'array',
        'latency_ms' => 'integer',
        'http_status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
