<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanFinding extends Model
{
    protected $table = 'db_scan_findings';

    protected $guarded = [];

    protected $casts = [
        'subject' => 'array',
        'evidence' => 'array',
        'snoozed_until' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
        'occurrences' => 'integer',
    ];

    public const OPEN_STATUSES = ['open', 'acknowledged'];

    public const SUPPRESSED_STATUSES = ['snoozed', 'false_positive', 'allowlisted'];

    public const UNRESOLVED_STATUSES = ['open', 'acknowledged', 'snoozed', 'false_positive', 'allowlisted'];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function events()
    {
        return $this->hasMany(DbScanFindingEvent::class, 'finding_id');
    }

    public function observations()
    {
        return $this->hasMany(DbScanObservation::class, 'finding_id');
    }
}
