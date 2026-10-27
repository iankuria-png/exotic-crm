<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReceiptObservation extends Model
{
    protected $table = 'payment_receipt_observations';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = ['evidence_json' => 'array'];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
