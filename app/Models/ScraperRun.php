<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScraperRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'scraper_source_id',
        'platform_id',
        'initiated_by',
        'mode',
        'status',
        'reason',
        'discovered_count',
        'created_count',
        'duplicate_count',
        'skipped_count',
        'error_count',
        'preview',
        'result',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'preview' => 'array',
        'result' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function source()
    {
        return $this->belongsTo(ScraperSource::class, 'scraper_source_id');
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function initiatedBy()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
