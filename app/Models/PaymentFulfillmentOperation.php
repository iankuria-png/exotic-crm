<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentFulfillmentOperation extends Model
{
    protected $table = 'payment_fulfillment_operations';

    protected $primaryKey = 'id';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['purchase_snapshot' => 'array', 'target_snapshot' => 'array', 'result_json' => 'array', 'next_retry_at' => 'datetime', 'dispatch_started_at' => 'datetime'];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
