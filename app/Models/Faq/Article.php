<?php

namespace App\Models\Faq;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    use HasFactory;

    protected $table = 'faq_articles';

    protected $fillable = [
        'category_id',
        'slug',
        'title',
        'summary',
        'body',
        'body_draft',
        'status',
        'author_id',
        'last_editor_id',
        'position',
        'view_count',
        'helpful_count',
        'unhelpful_count',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function lastEditor()
    {
        return $this->belongsTo(User::class, 'last_editor_id');
    }

    public function ctas()
    {
        return $this->hasMany(Cta::class, 'article_id')->orderBy('position')->orderBy('id');
    }

    public function media()
    {
        return $this->hasMany(Media::class, 'article_id')->orderBy('position')->orderBy('id');
    }

    public function feedback()
    {
        return $this->hasMany(Feedback::class, 'article_id');
    }
}
