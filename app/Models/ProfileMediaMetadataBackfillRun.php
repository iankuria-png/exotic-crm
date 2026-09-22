<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileMediaMetadataBackfillRun extends Model
{
    use HasFactory;

    public const SCOPE_SELECTED = 'selected';

    public const SCOPE_MARKET = 'market';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'platform_id',
        'requested_by',
        'scope',
        'client_ids',
        'status',
        'candidate_count',
        'processed_count',
        'attachments_updated_count',
        'skipped_count',
        'failed_count',
        'cursor_client_id',
        'notes',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'platform_id' => 'integer',
        'requested_by' => 'integer',
        'client_ids' => 'array',
        'candidate_count' => 'integer',
        'processed_count' => 'integer',
        'attachments_updated_count' => 'integer',
        'skipped_count' => 'integer',
        'failed_count' => 'integer',
        'cursor_client_id' => 'integer',
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

    public function isRunning(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }
}
