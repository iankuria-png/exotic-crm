<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Editor feedback on generated bios. Drives the "learn from past feedback"
 * loop in BioGenerationService — recent rows are summarised and injected
 * into the system prompt.
 */
class SeoBioFeedback extends Model
{
    use HasFactory;

    protected $table = 'seo_bio_feedback';

    protected $fillable = [
        'platform_id',
        'client_id',
        'wp_post_id',
        'user_id',
        'provider_used',
        'rating',
        'tag',
        'comment',
        'accepted',
        'score',
        'generation_options',
        'bio_html',
    ];

    protected $casts = [
        'rating'             => 'integer',
        'accepted'           => 'boolean',
        'score'              => 'integer',
        'generation_options' => 'array',
        'platform_id'        => 'integer',
        'client_id'          => 'integer',
        'wp_post_id'         => 'integer',
        'user_id'            => 'integer',
    ];

    /** Tags the UI exposes as quick-pick chips. */
    public const ALLOWED_TAGS = [
        'perfect',
        'too_long',
        'too_short',
        'too_generic',
        'off_tone',
        'repetitive',
        'missing_contact',
        'too_formal',
        'too_casual',
        'inaccurate',
        'other',
    ];
}
