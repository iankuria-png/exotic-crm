<?php

namespace App\Models\University;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class LessonMedia extends Model
{
    use HasFactory;

    protected $table = 'university_lesson_media';

    protected $fillable = [
        'lesson_id',
        'kind',
        'disk_path',
        'embed_url',
        'mime',
        'size_bytes',
        'caption',
        'order',
    ];

    public function getUrlAttribute(): ?string
    {
        if ($this->embed_url) {
            return $this->embed_url;
        }

        return $this->disk_path ? Storage::disk('public')->url($this->disk_path) : null;
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class, 'lesson_id');
    }
}
