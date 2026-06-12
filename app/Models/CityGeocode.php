<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CityGeocode extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_id',
        'canonical_key',
        'display_city',
        'latitude',
        'longitude',
        'status',
        'importance',
        'match_type',
        'attempts',
        'last_attempted_at',
        'failure_reason',
        'source',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'importance' => 'decimal:5',
        'attempts' => 'integer',
        'last_attempted_at' => 'datetime',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }
}
