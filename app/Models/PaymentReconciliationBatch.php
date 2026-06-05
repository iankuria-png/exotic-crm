<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReconciliationBatch extends Model
{
    protected $fillable = [
        'platform_id',
        'uploaded_by',
        'file_name',
        'file_mime',
        'source_type',
        'status',
        'reason',
        'closed_by',
        'closed_at',
        'metadata',
        'total_rows',
        'matched_rows',
        'mismatch_rows',
        'missing_rows',
        'unverifiable_rows',
        'duplicate_rows',
        'resolved_rows',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function rows()
    {
        return $this->hasMany(PaymentReconciliationRow::class, 'batch_id');
    }
}
