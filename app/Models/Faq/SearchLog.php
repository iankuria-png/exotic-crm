<?php

namespace App\Models\Faq;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SearchLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'faq_search_log';

    protected $fillable = [
        'user_id',
        'query',
        'result_count',
        'clicked_article_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function article()
    {
        return $this->belongsTo(Article::class, 'clicked_article_id');
    }
}
