<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ForecastScenario extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'created_by',
        'platform_id',
        'mode',
        'baseline_from',
        'baseline_to',
        'horizon_days',
        'horizon_ends_on',
        'reporting_currency',
        'target_amount',
        'risk_band',
        'levers',
        'snapshot',
        'config_digest',
        'actual_total',
        'variance_percent',
        'scored_at',
    ];

    protected $casts = [
        'baseline_from' => 'date',
        'baseline_to' => 'date',
        'horizon_ends_on' => 'date',
        'horizon_days' => 'integer',
        'target_amount' => 'decimal:2',
        'levers' => 'array',
        'snapshot' => 'array',
        'actual_total' => 'decimal:2',
        'variance_percent' => 'decimal:2',
        'scored_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }
}
