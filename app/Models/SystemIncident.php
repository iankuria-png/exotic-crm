<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single degradation transition, with the reading that caused it and the
 * threshold it was measured against, so the row still explains itself months
 * later without anyone having to reconstruct what the settings were that day.
 */
class SystemIncident extends Model
{
    use HasFactory;

    public const ORIGIN_AUTOMATIC = 'automatic';
    public const ORIGIN_MANUAL = 'manual';

    protected $fillable = [
        'from_level',
        'to_level',
        'trigger_signal',
        'trigger_value',
        'threshold',
        'origin',
        'actor_id',
        'snapshot',
        'started_at',
        'resolved_at',
    ];

    protected $casts = [
        'from_level' => 'integer',
        'to_level' => 'integer',
        'trigger_value' => 'float',
        'threshold' => 'float',
        'actor_id' => 'integer',
        'snapshot' => 'array',
        'started_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
