<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanSweep extends Model
{
    protected $table = 'db_scan_sweeps';

    protected $guarded = [];

    protected $casts = [
        'high_water' => 'array',
        'cursors' => 'array',
        'coverage' => 'array',
        'oldest_observed_at' => 'datetime',
        'newest_observed_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_served_at' => 'datetime',
        'finished_at' => 'datetime',
        'continuation_paused' => 'boolean',
    ];

    public const OPEN = ['running'];

    public function isOpen(): bool
    {
        return $this->status === 'running' && $this->expires_at && $this->expires_at->isFuture();
    }
}
