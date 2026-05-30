<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BriefingRun extends Model
{
    protected $fillable = [
        'audience',
        'period',
        'period_start',
        'period_end',
        'triggered_by',
        'dry_run',
        'status',
        'cost_usd',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'dry_run' => 'boolean',
        'cost_usd' => 'decimal:6',
    ];

    public function briefings(): HasMany
    {
        return $this->hasMany(Briefing::class);
    }
}
