<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WatermarkRemovalAttempt extends Model
{
    /**
     * Stable slugs for grouping. The human-readable sentence lives in `reason`;
     * counting that directly would splinter on the numbers inside it.
     */
    public const OUTCOME_APPLIED = 'applied';
    public const OUTCOME_NOT_THIS_WATERMARK = 'not_this_watermark';
    public const OUTCOME_NOT_CONFIGURED = 'not_configured';
    public const OUTCOME_BARELY_LANDS = 'barely_lands';
    public const OUTCOME_UNREADABLE = 'unreadable';
    public const OUTCOME_WRITE_FAILED = 'write_failed';
    public const OUTCOME_ERROR = 'error';

    public const OUTCOME_LABELS = [
        self::OUTCOME_APPLIED => 'Removed',
        self::OUTCOME_NOT_THIS_WATERMARK => 'Different watermark',
        self::OUTCOME_NOT_CONFIGURED => 'Market not configured',
        self::OUTCOME_BARELY_LANDS => 'Stamp barely lands',
        self::OUTCOME_UNREADABLE => 'Image unreadable',
        self::OUTCOME_WRITE_FAILED => 'Could not write result',
        self::OUTCOME_ERROR => 'Unexpected error',
    ];

    protected $fillable = [
        'source_platform_id',
        'pbn_site_id',
        'pbn_seed_item_id',
        'batch_id',
        'context',
        'applied',
        'outcome',
        'reason',
        'image_url',
        'image_size',
        'stamp_size',
        'implausible_ratio',
        'out_of_gamut_ratio',
        'landed_px',
        'stats',
    ];

    protected $casts = [
        'applied' => 'boolean',
        'stats' => 'array',
        'implausible_ratio' => 'float',
        'out_of_gamut_ratio' => 'float',
        'landed_px' => 'integer',
    ];

    public function sourcePlatform()
    {
        return $this->belongsTo(Platform::class, 'source_platform_id');
    }

    public function label(): string
    {
        return self::OUTCOME_LABELS[$this->outcome] ?? $this->outcome;
    }
}
