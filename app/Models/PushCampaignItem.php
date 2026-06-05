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
        'provider_meta',
        'replaces_item_id',
        'replacement_round',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivery_stats' => 'array',
        'provider_meta' => 'array',
        'replacement_round' => 'integer',
    ];

    public function campaign()
    {
        return $this->belongsTo(PushCampaign::class, 'campaign_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function replacementParent()
    {
        return $this->belongsTo(self::class, 'replaces_item_id');
    }

    public function replacements()
    {
        return $this->hasMany(self::class, 'replaces_item_id');
    }
}
