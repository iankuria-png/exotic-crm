<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientProfileMetric extends Model
{
    protected $fillable = [
        'client_id',
        'platform_id',
        'wp_post_id',
        'period_start',
        'period_end',
        'views',
        'unique_views',
        'contacts',
        'engagement',
        'previous_views',
        'captured_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'captured_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }
}
