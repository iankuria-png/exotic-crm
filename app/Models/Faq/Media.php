<?php

namespace App\Models\Faq;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use HasFactory;

    protected $table = 'faq_article_media';

    protected $fillable = [
        'article_id',
        'kind',
        'disk_path',
        'mime',
        'size_bytes',
        'caption',
        'position',
    ];

    protected $appends = [
        'url',
    ];

    public function article()
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->disk_path);
    }
}
