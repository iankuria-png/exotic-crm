<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimelineEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'platform_id', 'entity_type', 'entity_id',
        'event_type', 'actor_id', 'content',
    ];

    protected $casts = [
        'content' => 'array',
        'created_at' => 'datetime',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function scopeForEntity($query, $type, $id)
    {
        return $query->where('entity_type', $type)->where('entity_id', $id);
    }
}
