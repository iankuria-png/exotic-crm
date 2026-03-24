<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PushCampaignItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'client_id',
        'profile_url',
        'wp_post_id',
        'profile_name',
        'profile_city',
        'profile_phone',
        'profile_image_url',
        'profile_age',
        'custom_message',
        'scheduled_at',
        'date_label',
        'status',
        'provider_notification_id',
        'sent_at',
        'error_message',
        'delivery_stats',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivery_stats' => 'array',
    ];

    public function campaign()
    {
        return $this->belongsTo(PushCampaign::class, 'campaign_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
