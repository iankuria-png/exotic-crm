<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One SEO Recovery batch: the eligibility config it used, what it did, and
 * enough bookkeeping to revert it.
 */
class LifecycleRestoreRun extends Model
{
    use HasFactory;

    public const MODE_DRY = 'dry';
    public const MODE_LIVE = 'live';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REVERTED = 'reverted';

    protected $fillable = [
        'platform_id',
        'requested_by',
        'mode',
        'status',
        'target_state',
        'batch_limit',
        'filters',
        'candidate_count',
        'restored_count',
        'skipped_count',
        'failed_count',
        'started_at',
        'finished_at',
        'notes',
    ];

    protected $casts = [
        'platform_id' => 'integer',
        'requested_by' => 'integer',
        'batch_limit' => 'integer',
        'filters' => 'array',
        'candidate_count' => 'integer',
        'restored_count' => 'integer',
        'skipped_count' => 'integer',
        'failed_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** Profiles this run restored — the revertible cohort. */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'lifecycle_restore_run_id');
    }

    public function isLive(): bool
    {
        return $this->mode === self::MODE_LIVE;
    }

    /** A live, completed run still holding a cohort can be put back. */
    public function isRevertible(): bool
    {
        return $this->isLive()
            && $this->status === self::STATUS_COMPLETED
            && $this->restored_count > 0;
    }
}
