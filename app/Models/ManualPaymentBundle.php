<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ManualPaymentBundle extends Model
{
    use HasFactory;

    public const STATUS_COMMITTING = 'committing';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_COMPENSATION_FAILED = 'compensation_failed';
    public const STATUS_VOIDED = 'voided';

    public const AUDIT_PENDING_FINANCE_REVIEW = 'pending_finance_review';
    public const AUDIT_NEEDS_FINANCE_RESOLUTION = 'needs_finance_resolution';
    public const AUDIT_RESOLVED = 'resolved';
    public const AUDIT_VOIDED = 'voided';

    protected $fillable = [
        'platform_id',
        'reference_root',
        'total_amount',
        'allocated_amount',
        'unallocated_amount',
        'currency',
        'reason',
        'status',
        'audit_state',
        'idempotency_key',
        'created_by',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'allocated_amount' => 'decimal:2',
        'unallocated_amount' => 'decimal:2',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'manual_payment_bundle_id');
    }
}
