<?php

namespace App\Models\University;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Course extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'university_courses';

    protected $fillable = [
        'slug',
        'title',
        'summary',
        'cover_image_path',
        'status',
        'visibility',
        'required_for_roles',
        'prerequisite_course_id',
        'order',
        'author_id',
        'published_at',
    ];

    protected $casts = [
        'required_for_roles' => 'array',
        'published_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function getCoverImageUrlAttribute(): ?string
    {
        return $this->cover_image_path ? Storage::disk('public')->url($this->cover_image_path) : null;
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function modules()
    {
        return $this->hasMany(Module::class, 'course_id')->orderBy('order')->orderBy('id');
    }

    public function lessons()
    {
        return $this->hasManyThrough(Lesson::class, Module::class, 'course_id', 'module_id');
    }

    public function certifications()
    {
        return $this->hasMany(Certification::class, 'course_id')->orderBy('id');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }
}
