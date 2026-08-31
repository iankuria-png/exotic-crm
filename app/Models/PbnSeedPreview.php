<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PbnSeedPreview extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'preview_token',
        'pbn_site_id',
        'created_by',
        'payload_hash',
        'expires_at',
        'status',
        'source_platform_ids',
        'targets',
        'copy_policy',
        'candidate_summary',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'source_platform_ids' => 'array',
        'targets' => 'array',
        'copy_policy' => 'array',
        'candidate_summary' => 'array',
    ];

    public function pbnSite()
    {
        return $this->belongsTo(PbnSite::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isUsableBy(User $actor, string $payloadHash): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && (int) $this->created_by === (int) $actor->id
            && hash_equals((string) $this->payload_hash, $payloadHash)
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
