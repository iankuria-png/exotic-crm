<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReconciliationBatch extends Model
{
    protected $fillable = [
        'platform_id',
        'platform_ids',
        'fallback_currency',
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
        'platform_ids' => 'array',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    /**
     * The full set of market ids this batch reconciles against. Falls back to the
     * primary platform_id for legacy single-market batches.
     *
     * @return array<int,int>
     */
    public function platformIdSet(): array
    {
        $ids = is_array($this->platform_ids) ? $this->platform_ids : [];
        if (empty($ids) && $this->platform_id) {
            $ids = [(int) $this->platform_id];
        }

        return array_values(array_unique(array_map('intval', $ids)));
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
