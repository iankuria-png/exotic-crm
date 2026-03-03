<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PushSubscriberSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_id',
        'provider',
        'total_subscribers',
        'active_subscribers',
        'snapshot_date',
        'raw_response',
    ];

    protected $casts = [
        'total_subscribers' => 'integer',
        'active_subscribers' => 'integer',
        'snapshot_date' => 'date',
        'raw_response' => 'array',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }
}
