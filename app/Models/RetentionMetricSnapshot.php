<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RetentionMetricSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'snapshot_date',
        'platform_id',
        'active_baseline_count',
        'churned_count',
        'logo_churn_30d',
        'meta',
        'computed_at',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'active_baseline_count' => 'integer',
        'churned_count' => 'integer',
        'logo_churn_30d' => 'float',
        'meta' => 'array',
        'computed_at' => 'datetime',
    ];
}
