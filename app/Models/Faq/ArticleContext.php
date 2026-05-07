<?php

namespace App\Models\Faq;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArticleContext extends Model
{
    use HasFactory;

    protected $table = 'faq_article_contexts';

    protected $fillable = [
        'article_id',
        'crm_page',
        'surface',
        'context_kind',
        'priority',
    ];

    public function article()
    {
        return $this->belongsTo(Article::class, 'article_id');
    }
}
