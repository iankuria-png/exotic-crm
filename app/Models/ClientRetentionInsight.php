<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientRetentionInsight extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'platform_id',
        'score',
        'band',
        'primary_tag',
        'secondary_tags',
        'component_scores',
        'top_drivers',
        'signals',
        'computed_at',
    ];

    protected $casts = [
        'score' => 'integer',
        'secondary_tags' => 'array',
        'component_scores' => 'array',
        'top_drivers' => 'array',
        'signals' => 'array',
        'computed_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }
}
