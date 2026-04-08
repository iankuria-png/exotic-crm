<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentManualSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'client_id',
        'platform_id',
        'product_id',
        'duration_key',
        'manual_method_key',
        'activated_on_submit',
        'destination_snapshot_json',
        'instruction_snapshot_json',
        'sender_name',
        'transaction_reference',
        'customer_note',
        'proof_disk',
        'proof_path',
        'proof_mime',
        'proof_size_bytes',
        'review_decision',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'activated_on_submit' => 'boolean',
        'destination_snapshot_json' => 'array',
        'instruction_snapshot_json' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
