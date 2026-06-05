<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_id', 'title', 'category', 'channel',
        'subject', 'body', 'variables', 'status', 'is_quick_reply',
    ];

    protected $casts = [
        'variables' => 'array',
        'is_quick_reply' => 'boolean',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function renewalCampaigns()
    {
        return $this->hasMany(RenewalCampaign::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForChannel($query, $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeQuickReply($query)
    {
        return $query->where('is_quick_reply', true);
    }
}
