<?php

namespace App\Models\University;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Certification extends Model
{
    use HasFactory;

    protected $table = 'university_certifications';

    protected $fillable = [
        'course_id',
        'title',
        'slug',
        'description',
        'pass_threshold',
        'time_limit_minutes',
        'question_count',
        'max_attempts_per_window',
        'attempt_window_days',
        'validity_months',
        'randomize_questions',
        'randomize_options',
        'show_explanations_on_fail',
        'allow_review_before_submit',
        'cert_template_id',
        'status',
    ];

    protected $casts = [
        'randomize_questions' => 'boolean',
        'randomize_options' => 'boolean',
        'show_explanations_on_fail' => 'boolean',
        'allow_review_before_submit' => 'boolean',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function questions()
    {
        return $this->hasMany(Question::class, 'certification_id')->orderBy('order')->orderBy('id');
    }

    public function attempts()
    {
        return $this->hasMany(Attempt::class, 'certification_id');
    }

    public function certificates()
    {
        return $this->hasMany(Certificate::class, 'certification_id');
    }
}
