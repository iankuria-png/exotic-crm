<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportingFxRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'source_currency',
        'target_currency',
        'rate_date',
        'rate',
        'fetched_at',
        'metadata',
    ];

    protected $casts = [
        'rate_date' => 'date',
        'fetched_at' => 'datetime',
        'metadata' => 'array',
        'rate' => 'decimal:10',
    ];
}
