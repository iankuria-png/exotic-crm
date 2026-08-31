<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PbnSeedItem extends Model
{
    public const STATUS_SELECTED = 'selected';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_CREATED = 'created';
    public const STATUS_MEDIA_PENDING = 'media_pending';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED_DUPLICATE = 'skipped_duplicate';
    public const STATUS_REVERTED = 'reverted';

    protected $fillable = [
        'batch_id',
        'target_id',
        'pbn_site_id',
        'source_platform_id',
        'source_client_id',
        'source_wp_post_id',
        'target_region_id',
        'target_city_id',
        'target_wp_post_id',
        'target_wp_user_id',
        'status',
        'duplicate_state',
        'quality_score',
        'payload_hash',
        'eligibility_snapshot',
        'failure_reason',
        'provision_started_at',
        'provision_finished_at',
        'original_target_post_status',
        'reverted_at',
        'reverted_by',
        'revert_reason',
        'revert_failure_reason',
    ];

    protected $casts = [
        'target_id' => 'integer',
        'pbn_site_id' => 'integer',
        'source_platform_id' => 'integer',
        'source_client_id' => 'integer',
        'source_wp_post_id' => 'integer',
        'target_region_id' => 'integer',
        'target_city_id' => 'integer',
        'target_wp_post_id' => 'integer',
        'target_wp_user_id' => 'integer',
        'quality_score' => 'integer',
        'eligibility_snapshot' => 'array',
        'provision_started_at' => 'datetime',
        'provision_finished_at' => 'datetime',
        'reverted_at' => 'datetime',
        'reverted_by' => 'integer',
    ];

    public function batch()
    {
        return $this->belongsTo(PbnSeedBatch::class, 'batch_id');
    }

    public function target()
    {
        return $this->belongsTo(PbnSeedTarget::class, 'target_id');
    }

    public function pbnSite()
    {
        return $this->belongsTo(PbnSite::class);
    }

    public function sourcePlatform()
    {
        return $this->belongsTo(Platform::class, 'source_platform_id');
    }

    public function sourceClient()
    {
        return $this->belongsTo(Client::class, 'source_client_id');
    }

    public function reverter()
    {
        return $this->belongsTo(User::class, 'reverted_by');
    }

    public function events()
    {
        return $this->hasMany(PbnSeedEvent::class, 'item_id');
    }
}
