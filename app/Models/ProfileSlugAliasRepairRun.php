<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One market's repair of old profile URLs that point, or could point, at the
 * wrong profile. WordPress does the work (exotic-crm-sync 1.3.13); this row
 * tracks progress and keeps the released aliases so the run can be restored.
 */
class ProfileSlugAliasRepairRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RESTORING = 'restoring';

    public const STATUS_RESTORED = 'restored';

    public const ACTIVE_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_RESTORING,
    ];

    protected $fillable = [
        'platform_id',
        'requested_by',
        'status',
        'audit_summary',
        'targets',
        'target_urls',
        'urls_processed',
        'aliases_released',
        'backup',
        'restored_count',
        'notes',
        'started_at',
        'finished_at',
        'restored_by',
        'restored_at',
    ];

    protected $casts = [
        'platform_id' => 'integer',
        'requested_by' => 'integer',
        'audit_summary' => 'array',
        'targets' => 'array',
        'target_urls' => 'integer',
        'urls_processed' => 'integer',
        'aliases_released' => 'integer',
        'backup' => 'array',
        'restored_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'restored_by' => 'integer',
        'restored_at' => 'datetime',
    ];

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function restorer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    /** Whether the run repairs chosen URLs rather than the whole market. */
    public function isScoped(): bool
    {
        return is_array($this->targets) && $this->targets !== [];
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /** A finished run with released aliases can be put back once. */
    public function canRestore(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true)
            && count($this->backup ?? []) > 0;
    }
}
