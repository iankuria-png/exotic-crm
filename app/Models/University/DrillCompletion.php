<?php

namespace App\Models\University;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DrillCompletion extends Model
{
    use HasFactory;

    protected $table = 'university_drill_completions';

    protected $fillable = [
        'user_id',
        'drill_id',
        'completed_on',
        'correct',
        'selected_index',
    ];

    protected $casts = [
        'completed_on' => 'date',
        'correct' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function drill()
    {
        return $this->belongsTo(DailyDrill::class, 'drill_id');
    }
}
