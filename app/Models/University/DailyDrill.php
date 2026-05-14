<?php

namespace App\Models\University;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyDrill extends Model
{
    use HasFactory;

    protected $table = 'university_daily_drills';

    protected $fillable = [
        'prompt',
        'scenario_context',
        'explanation',
        'options',
        'correct_index',
        'topic_tag',
        'is_active',
    ];

    protected $casts = [
        'options' => 'array',
        'is_active' => 'boolean',
    ];
}
