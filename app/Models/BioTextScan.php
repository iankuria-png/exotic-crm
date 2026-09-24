<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One market's check of profile bios for broken text (garbled accents, lost
 * characters, AI leftovers…), and the repair and restore that follow it.
 * The findings keep every bio as it was before the repair wrote to it.
 */
class BioTextScan extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SCANNING = 'scanning';

    public const STATUS_SCANNED = 'scanned';

    public const STATUS_REPAIRING = 'repairing';

    public const STATUS_REPAIRED = 'repaired';

    public const STATUS_RESTORING = 'restoring';

    public const STATUS_RESTORED = 'restored';

    public const STATUS_FAILED = 'failed';

    public const ACTIVE_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_SCANNING,
        self::STATUS_REPAIRING,
        self::STATUS_RESTORING,
    ];

    protected $fillable = [
        'platform_id',
        'requested_by',
        'status',
        'total_profiles',
        'profiles_scanned',
        'profiles_unreadable',
        'cursor_client_id',
        'profiles_affected',
        'profiles_fixable',
        'issue_counts',
        'repair_requested_by',
        'repair_target',
        'repaired',
        'repair_unchanged',
        'repair_failed',
        'restore_requested_by',
        'restore_target',
        'restored',
        'restore_failed',
        'notes',
        'started_at',
        'scanned_at',
        'repair_started_at',
        'finished_at',
    ];

    protected $casts = [
        'platform_id' => 'integer',
        'requested_by' => 'integer',
        'total_profiles' => 'integer',
        'profiles_scanned' => 'integer',
        'profiles_unreadable' => 'integer',
        'cursor_client_id' => 'integer',
        'profiles_affected' => 'integer',
        'profiles_fixable' => 'integer',
        'issue_counts' => 'array',
        'repair_requested_by' => 'integer',
        'repair_target' => 'integer',
        'repaired' => 'integer',
        'repair_unchanged' => 'integer',
        'repair_failed' => 'integer',
        'restore_requested_by' => 'integer',
        'restore_target' => 'integer',
        'restored' => 'integer',
        'restore_failed' => 'integer',
        'started_at' => 'datetime',
        'scanned_at' => 'datetime',
        'repair_started_at' => 'datetime',
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

    public function repairRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'repair_requested_by');
    }

    public function restoreRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restore_requested_by');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(BioTextFinding::class, 'scan_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /** Findings can be repaired or restored once the whole market has been read. */
    public function isReviewable(): bool
    {
        return $this->scanned_at !== null && ! $this->isActive();
    }
}
