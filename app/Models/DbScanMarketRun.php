<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanMarketRun extends Model
{
    protected $table = 'db_scan_market_runs';

    protected $guarded = [];

    protected $casts = [
        'generation' => 'integer',
        'heartbeat_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'deadline_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'resolution_completed_at' => 'datetime',
        'active_seconds' => 'float',
        'budget_seconds' => 'integer',
        'transient_failures' => 'integer',
        'cursor' => 'array',
        'metrics' => 'array',
        'test_samples' => 'array',
    ];

    public const TERMINAL = ['stopped', 'completed', 'completed_with_gaps', 'partial', 'unreachable', 'skipped_unhealthy', 'skipped_shed', 'failed'];

    public const WAITING = ['queued', 'waiting_lock', 'paused'];

    public function pass()
    {
        return $this->belongsTo(DbScanPass::class, 'pass_id');
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function sweep()
    {
        return $this->belongsTo(DbScanSweep::class, 'sweep_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }
}
