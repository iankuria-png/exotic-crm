<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single profile-URL row inside a {@see SeoBioBatch}.
 *
 * status lifecycle:
 *   queued → processing → generated | failed | unresolved
 *   generated → accepted | skipped (after editor review)
 */
class SeoBioBatchRow extends Model
{
    use HasFactory;

    protected $table = 'seo_bio_batch_rows';

    protected $fillable = [
        'batch_id',
        'row_index',
        'input_url',
        'input_text',
        'wp_post_id',
        'client_id',
        'profile_name',
        'status',
        'bio_html',
        'score',
        'breakdown',
        'provider_used',
        'error',
        'processed_at',
    ];

    protected $casts = [
        'breakdown'    => 'array',
        'row_index'    => 'integer',
        'wp_post_id'   => 'integer',
        'client_id'    => 'integer',
        'batch_id'     => 'integer',
        'score'        => 'integer',
        'processed_at' => 'datetime',
    ];

    public const STATUS_QUEUED     = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_GENERATED  = 'generated';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_ACCEPTED   = 'accepted';
    public const STATUS_SKIPPED    = 'skipped';
    public const STATUS_UNRESOLVED = 'unresolved';

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SeoBioBatch::class, 'batch_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
