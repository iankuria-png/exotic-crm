<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PbnSeedBatch extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'pbn_site_id',
        'created_by',
        'status',
        'source_platform_ids',
        'target_count',
        'selected_count',
        'created_count',
        'failed_count',
        'warnings',
        'copy_policy',
        'notes',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'source_platform_ids' => 'array',
        'target_count' => 'integer',
        'selected_count' => 'integer',
        'created_count' => 'integer',
        'failed_count' => 'integer',
        'warnings' => 'array',
        'copy_policy' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function pbnSite()
    {
        return $this->belongsTo(PbnSite::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function targets()
    {
        return $this->hasMany(PbnSeedTarget::class, 'batch_id');
    }

    public function items()
    {
        return $this->hasMany(PbnSeedItem::class, 'batch_id');
    }
}
