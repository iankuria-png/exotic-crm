<?php

namespace App\Models\University;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LessonFeedback extends Model
{
    use HasFactory;

    protected $table = 'university_lesson_feedback';

    protected $fillable = [
        'lesson_id',
        'user_id',
        'rating',
        'comment',
    ];

    public function lesson()
    {
        return $this->belongsTo(Lesson::class, 'lesson_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
