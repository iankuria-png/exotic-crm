<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentGoalOverride extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'platform_id',
        'metric',
        'target',
        'target_currency',
        'period',
        'set_by',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'platform_id' => 'integer',
        'target' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function setter()
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
