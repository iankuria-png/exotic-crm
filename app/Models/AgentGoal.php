<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentGoal extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_id',
        'metric',
        'target',
        'period',
        'set_by',
    ];

    protected $casts = [
        'platform_id' => 'integer',
        'target' => 'integer',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function setter()
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
