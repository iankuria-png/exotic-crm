<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientSyncRun extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_STALE = 'stale';

    protected $fillable = [
        'platform_id',
        'initiated_by',
        'origin',
        'mode',
        'protocol',
        'status',
        'processed',
        'created',
        'updated',
        'skipped',
        'tombstones_processed',
        'errors',
        'error_details',
        'capability_snapshot',
        'reason',
        'fallback_reason',
        'started_at',
        'finished_at',
        'last_heartbeat_at',
        'run_upper_bound_modified_at',
        'cursor_modified_at',
        'cursor_post_id',
        'tombstone_upper_bound_removed_at',
        'tombstone_cursor_removed_at',
        'tombstone_cursor_post_id',
        'checkpoint_before_run',
        'checkpoint_after_run',
    ];

    protected $casts = [
        'processed' => 'integer',
        'created' => 'integer',
        'updated' => 'integer',
        'skipped' => 'integer',
        'tombstones_processed' => 'integer',
        'errors' => 'integer',
        'error_details' => 'array',
        'capability_snapshot' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'run_upper_bound_modified_at' => 'datetime',
        'cursor_modified_at' => 'datetime',
        'cursor_post_id' => 'integer',
        'tombstone_upper_bound_removed_at' => 'datetime',
        'tombstone_cursor_removed_at' => 'datetime',
        'tombstone_cursor_post_id' => 'integer',
        'checkpoint_before_run' => 'datetime',
        'checkpoint_after_run' => 'datetime',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function initiatedBy()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function scopeForPlatform($query, int $platformId)
    {
        return $query->where('platform_id', $platformId);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }
}
