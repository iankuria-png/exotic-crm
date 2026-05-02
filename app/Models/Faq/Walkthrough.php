<?php

namespace App\Models\Faq;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Walkthrough extends Model
{
    use HasFactory;

    protected $table = 'faq_walkthroughs';

    protected $fillable = [
        'slug',
        'name',
        'steps',
    ];

    protected $casts = [
        'steps' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
