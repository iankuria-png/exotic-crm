<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScraperSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_id',
        'name',
        'source_url',
        'parser_profile',
        'parser_rules',
        'fetch_schedule',
        'dedupe_mode',
        'is_active',
        'compliance_ack_robots',
        'compliance_ack_tos',
        'compliance_notes',
        'last_run_at',
        'last_run_status',
        'last_run_summary',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'parser_rules' => 'array',
        'is_active' => 'boolean',
        'compliance_ack_robots' => 'boolean',
        'compliance_ack_tos' => 'boolean',
        'last_run_at' => 'datetime',
        'last_run_summary' => 'array',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function runs()
    {
        return $this->hasMany(ScraperRun::class);
    }
}
