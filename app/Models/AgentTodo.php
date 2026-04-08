<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentTodo extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'content',
        'status',
        'goal_id',
        'due_at',
        'sort_order',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'goal_id' => 'integer',
        'sort_order' => 'integer',
        'due_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function goal()
    {
        return $this->belongsTo(AgentGoal::class, 'goal_id');
    }
}
