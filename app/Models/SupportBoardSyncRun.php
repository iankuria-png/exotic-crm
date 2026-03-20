<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportBoardSyncRun extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'platform_id',
        'initiated_by',
        'mode',
        'status',
        'candidates',
        'processed',
        'matched',
        'updated',
        'cleared',
        'unchanged',
        'errors',
        'error_details',
        'reason',
        'started_at',
        'finished_at',
        'last_heartbeat_at',
        'last_processed_client_id',
        'last_processed_client_name',
    ];

    protected $casts = [
        'candidates' => 'integer',
        'processed' => 'integer',
        'matched' => 'integer',
        'updated' => 'integer',
        'cleared' => 'integer',
        'unchanged' => 'integer',
        'errors' => 'integer',
        'error_details' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'last_processed_client_id' => 'integer',
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

    public function isRefresh(): bool
    {
        return $this->mode === 'refresh';
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }
}
