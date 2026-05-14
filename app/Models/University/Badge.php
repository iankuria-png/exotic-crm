<?php

namespace App\Models\University;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Badge extends Model
{
    use HasFactory;

    protected $table = 'university_badges';

    protected $fillable = [
        'code',
        'title',
        'description',
        'icon',
        'color',
        'criteria_kind',
        'criteria_config',
        'points',
    ];

    protected $casts = [
        'criteria_config' => 'array',
    ];

    public function awards()
    {
        return $this->hasMany(UserBadge::class, 'badge_id');
    }
}
