<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One profile whose bio a BioTextScan found broken text in. */
class BioTextFinding extends Model
{
    public const STATUS_FOUND = 'found';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_REPAIRED = 'repaired';

    /** Already clean when the repair reached it (edited since the scan). */
    public const STATUS_UNCHANGED = 'unchanged';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RESTORE_QUEUED = 'restore_queued';

    public const STATUS_RESTORED = 'restored';

    /** Statuses a repair may (re)start from. */
    public const REPAIRABLE_STATUSES = [self::STATUS_FOUND, self::STATUS_FAILED, self::STATUS_RESTORED];

    protected $fillable = [
        'scan_id',
        'platform_id',
        'client_id',
        'wp_post_id',
        'client_name',
        'issues',
        'kinds',
        'severity',
        'fixable',
        'status',
        'original_html',
        'original_hash',
        'repaired_html',
        'scrub_original_before',
        'error',
        'repaired_at',
        'restored_at',
    ];

    protected $casts = [
        'scan_id' => 'integer',
        'platform_id' => 'integer',
        'client_id' => 'integer',
        'wp_post_id' => 'integer',
        'issues' => 'array',
        'fixable' => 'boolean',
        'repaired_at' => 'datetime',
        'restored_at' => 'datetime',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(BioTextScan::class, 'scan_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
