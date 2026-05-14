<?php

namespace App\Models\University;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Streak extends Model
{
    use HasFactory;

    protected $table = 'university_streaks';

    protected $fillable = [
        'user_id',
        'current_streak',
        'longest_streak',
        'last_active_on',
    ];

    protected $casts = [
        'last_active_on' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
