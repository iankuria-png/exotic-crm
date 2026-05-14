<?php

namespace App\Models\University;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    use HasFactory;

    protected $table = 'university_lessons';

    protected $fillable = [
        'module_id',
        'slug',
        'title',
        'body',
        'body_draft',
        'duration_minutes',
        'order',
        'status',
    ];

    public function module()
    {
        return $this->belongsTo(Module::class, 'module_id');
    }

    public function media()
    {
        return $this->hasMany(LessonMedia::class, 'lesson_id')->orderBy('order')->orderBy('id');
    }

    public function progress()
    {
        return $this->hasMany(LessonProgress::class, 'lesson_id');
    }
}
