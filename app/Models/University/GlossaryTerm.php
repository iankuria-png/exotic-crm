<?php

namespace App\Models\University;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GlossaryTerm extends Model
{
    use HasFactory;

    protected $table = 'university_glossary_terms';

    protected $fillable = [
        'term',
        'slug',
        'definition',
        'aliases',
        'topic_tag',
        'playbook_url',
    ];

    protected $casts = [
        'aliases' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
