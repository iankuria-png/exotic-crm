<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SbLeadImportRun extends Model
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
        'created_leads',
        'updated_leads',
        'skipped_existing_client',
        'skipped_existing_lead',
        'errors',
        'error_details',
        'candidate_user_ids',
        'cursor_position',
        'reason',
        'started_at',
        'finished_at',
        'last_heartbeat_at',
        'last_processed_sb_user_id',
        'last_processed_name',
    ];

    protected $casts = [
        'candidates' => 'integer',
        'processed' => 'integer',
        'created_leads' => 'integer',
        'updated_leads' => 'integer',
        'skipped_existing_client' => 'integer',
        'skipped_existing_lead' => 'integer',
        'errors' => 'integer',
        'error_details' => 'array',
        'candidate_user_ids' => 'array',
        'cursor_position' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'last_processed_sb_user_id' => 'integer',
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

    public function isBootstrap(): bool
    {
        return $this->mode === 'bootstrap';
    }
}
