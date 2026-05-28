<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bulk bio generation batch — one editor's "generate bios for these N URLs"
 * request. Owns N {@see SeoBioBatchRow} children.
 */
class SeoBioBatch extends Model
{
    use HasFactory;

    protected $table = 'seo_bio_batches';

    protected $fillable = [
        'platform_id',
        'user_id',
        'language',
        'generation_options',
        'status',
        'total_rows',
        'processed_rows',
        'succeeded_rows',
        'failed_rows',
        'accepted_rows',
        'source_paste',
        'auto_save_to_wp',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'generation_options' => 'array',
        'auto_save_to_wp'    => 'boolean',
        'total_rows'         => 'integer',
        'processed_rows'     => 'integer',
        'succeeded_rows'     => 'integer',
        'failed_rows'        => 'integer',
        'accepted_rows'      => 'integer',
        'started_at'         => 'datetime',
        'finished_at'        => 'datetime',
    ];

    public const STATUS_QUEUED     = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY      = 'ready';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_CANCELLED  = 'cancelled';
    public const STATUS_FAILED     = 'failed';

    public function rows(): HasMany
    {
        return $this->hasMany(SeoBioBatchRow::class, 'batch_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_READY,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_FAILED,
        ], true);
    }
}
