<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutoPushBoostUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_id',
        'client_id',
        'actor_id',
        'auto_push_plan_id',
        'boost_hours',
        'limit_snapshot',
    ];

    protected $casts = [
        'boost_hours' => 'integer',
        'limit_snapshot' => 'array',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function plan()
    {
        return $this->belongsTo(AutoPushPlan::class, 'auto_push_plan_id');
    }
}
