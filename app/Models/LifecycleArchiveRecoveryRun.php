<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LifecycleArchiveRecoveryRun extends Model
{
    use HasFactory;

    public const MODE_POLICY = 'policy';

    public const MODE_OVERRIDE = 'override';

    public const SCOPE_SELECTED = 'selected';

    public const SCOPE_MARKET_ARCHIVED = 'market_archived';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'platform_id',
        'requested_by',
        'mode',
        'scope',
        'client_ids',
        'archive_after_days',
        'archive_deferred_until',
        'status',
        'candidate_count',
        'restored_count',
        'skipped_count',
        'failed_count',
        'notes',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'platform_id' => 'integer',
        'requested_by' => 'integer',
        'client_ids' => 'array',
        'archive_after_days' => 'integer',
        'archive_deferred_until' => 'datetime',
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

    public function isOverride(): bool
    {
        return $this->mode === self::MODE_OVERRIDE;
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }
}
