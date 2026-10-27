<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentRecoveryCase extends Model
{
    protected $table = 'payment_recovery_cases';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = ['evidence_json' => 'array', 'opened_at' => 'datetime', 'next_retry_at' => 'datetime', 'alerted_at' => 'datetime', 'escalated_at' => 'datetime', 'closed_at' => 'datetime'];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
