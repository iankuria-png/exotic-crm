<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScraperCapture extends Model
{
    protected $fillable = [
        'scraper_source_id',
        'platform_id',
        'user_id',
        'token_hash',
        'expected_host',
        'url',
        'storage_path',
        'bytes',
        'method',
        'status',
        'expires_at',
        'received_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    protected $hidden = ['token_hash', 'storage_path'];

    public function source()
    {
        return $this->belongsTo(ScraperSource::class, 'scraper_source_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
