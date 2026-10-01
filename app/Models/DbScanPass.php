<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanPass extends Model
{
    protected $table = 'db_scan_passes';

    protected $guarded = [];

    protected $casts = [
        'scope' => 'array',
        'rules' => 'array',
        'verbose' => 'boolean',
        'bypass_window' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public const TERMINAL = ['stopped', 'completed', 'completed_with_gaps', 'completed_with_errors'];

    public function runs()
    {
        return $this->hasMany(DbScanMarketRun::class, 'pass_id');
    }
}
