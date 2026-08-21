<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WeeklyPriority extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'status',
        'priority_level',
        'audience',
        'platform_id',
        'owner_user_id',
        'created_by',
        'source_type',
        'source_id',
        'week_start',
        'week_end',
        'due_at',
        'completion_mode',
        'metric_key',
        'target_operator',
        'target_value',
        'target_currency',
        'current_value',
        'last_evaluated_at',
        'completed_at',
        'metadata',
    ];

    protected $casts = [
        'platform_id' => 'integer',
        'owner_user_id' => 'integer',
        'created_by' => 'integer',
        'source_id' => 'integer',
        'week_start' => 'date',
        'week_end' => 'date',
        'due_at' => 'datetime',
        'target_value' => 'float',
        'current_value' => 'float',
        'last_evaluated_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
