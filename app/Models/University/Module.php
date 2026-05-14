<?php

namespace App\Models\University;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    use HasFactory;

    protected $table = 'university_modules';

    protected $fillable = [
        'course_id',
        'slug',
        'title',
        'summary',
        'order',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function lessons()
    {
        return $this->hasMany(Lesson::class, 'module_id')->orderBy('order')->orderBy('id');
    }
}
