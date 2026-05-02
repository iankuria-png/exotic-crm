<?php

namespace App\Models\Faq;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cta extends Model
{
    use HasFactory;

    protected $table = 'faq_article_ctas';

    protected $fillable = [
        'article_id',
        'position',
        'kind',
        'label',
        'target_path',
        'prefill_payload',
        'walkthrough_id',
    ];

    protected $casts = [
        'prefill_payload' => 'array',
    ];

    public function article()
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function walkthrough()
    {
        return $this->belongsTo(Walkthrough::class, 'walkthrough_id', 'slug');
    }
}
