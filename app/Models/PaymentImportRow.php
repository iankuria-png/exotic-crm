<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentImportRow extends Model
{
    protected $fillable = [
        'batch_id',
        'row_number',
        'status',
        'raw_row',
        'normalized_row',
        'validation_errors',
        'duplicate_type',
        'duplicate_payment_id',
        'transaction_reference_norm',
        'legacy_hash',
        'suggested_match',
        'applied_payment_id',
    ];

    protected $casts = [
        'raw_row' => 'array',
        'normalized_row' => 'array',
        'validation_errors' => 'array',
        'suggested_match' => 'array',
    ];

    public function batch()
    {
        return $this->belongsTo(PaymentImportBatch::class, 'batch_id');
    }

    public function duplicatePayment()
    {
        return $this->belongsTo(Payment::class, 'duplicate_payment_id');
    }

    public function appliedPayment()
    {
        return $this->belongsTo(Payment::class, 'applied_payment_id');
    }
}
