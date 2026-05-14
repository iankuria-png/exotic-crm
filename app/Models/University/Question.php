<?php

namespace App\Models\University;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    use HasFactory;

    protected $table = 'university_questions';

    protected $fillable = [
        'certification_id',
        'kind',
        'prompt',
        'scenario_context',
        'explanation',
        'topic_tag',
        'weight',
        'order',
    ];

    public function certification()
    {
        return $this->belongsTo(Certification::class, 'certification_id');
    }

    public function options()
    {
        return $this->hasMany(QuestionOption::class, 'question_id')->orderBy('order')->orderBy('id');
    }

    public function correctOption()
    {
        return $this->hasOne(QuestionOption::class, 'question_id')->where('is_correct', true);
    }
}
