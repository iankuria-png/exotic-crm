<?php

namespace App\Models\Faq;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $table = 'faq_categories';

    protected $fillable = [
        'slug',
        'name',
        'description',
        'crm_page',
        'position',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function articles()
    {
        return $this->hasMany(Article::class, 'category_id')->orderBy('position')->orderBy('title');
    }
}
