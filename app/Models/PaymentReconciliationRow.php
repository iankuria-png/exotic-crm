<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReconciliationRow extends Model
{
    protected $fillable = [
        'batch_id',
        'row_number',
        'raw_row',
        'external_name',
        'external_amount',
        'external_currency',
        'external_paid_at_text',
        'external_reference_raw',
        'transaction_reference_norm',
        'classification',
        'flags',
        'matched_payment_id',
        'matched_client_id',
        'matched_confirmed_by',
        'match_basis',
        'review_status',
        'review_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'raw_row' => 'array',
        'flags' => 'array',
        'match_basis' => 'array',
        'external_amount' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(PaymentReconciliationBatch::class, 'batch_id');
    }

    public function matchedPayment()
    {
        return $this->belongsTo(Payment::class, 'matched_payment_id');
    }

    public function matchedClient()
    {
        return $this->belongsTo(Client::class, 'matched_client_id');
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'matched_confirmed_by');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
