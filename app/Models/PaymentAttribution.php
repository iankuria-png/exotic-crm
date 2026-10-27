<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentAttribution extends Model
{
    protected $table = 'payment_attributions';

    protected $primaryKey = 'payment_id';

    protected $guarded = [];

    protected $casts = ['evidence_json' => 'array', 'client_first_seen_at' => 'datetime'];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
