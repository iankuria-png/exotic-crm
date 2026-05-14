<?php

namespace App\Models\University;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attempt extends Model
{
    use HasFactory;

    protected $table = 'university_attempts';

    protected $fillable = [
        'user_id',
        'certification_id',
        'question_order',
        'started_at',
        'submitted_at',
        'score_pct',
        'passed',
        'per_topic_breakdown',
        'time_spent_seconds',
    ];

    protected $casts = [
        'question_order' => 'array',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'score_pct' => 'float',
        'passed' => 'boolean',
        'per_topic_breakdown' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function certification()
    {
        return $this->belongsTo(Certification::class, 'certification_id');
    }

    public function answers()
    {
        return $this->hasMany(AttemptAnswer::class, 'attempt_id');
    }

    public function certificate()
    {
        return $this->hasOne(Certificate::class, 'attempt_id');
    }
}
