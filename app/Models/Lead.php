<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_id', 'wp_user_id', 'wp_post_id',
        'name', 'phone_normalized', 'email', 'source_url',
        'source', 'status', 'assigned_to',
        'first_contact_at', 'last_contact_at',
        'response_time_seconds', 'converted_client_id',
        'archived_at',
        'sb_user_id', 'sb_conversation_id', 'sb_user_type',
        'sb_last_activity_at', 'sb_metadata_snapshot',
    ];

    protected $casts = [
        'first_contact_at' => 'datetime',
        'last_contact_at' => 'datetime',
        'archived_at' => 'datetime',
        'sb_last_activity_at' => 'datetime',
        'sb_metadata_snapshot' => 'array',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function assignedAgent()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function convertedClient()
    {
        return $this->belongsTo(Client::class, 'converted_client_id');
    }

    public function deals()
    {
        return $this->hasMany(Deal::class);
    }

    public function timelineEvents()
    {
        return $this->morphMany(TimelineEvent::class, 'entity', 'entity_type', 'entity_id')
            ->where('entity_type', 'lead');
    }

    public function whatsAppMessages()
    {
        return $this->hasMany(WhatsAppMessage::class);
    }

    public function scopeNew($query)
    {
        return $query->where('status', 'new');
    }

    public function scopeForPlatform($query, $platformId)
    {
        return $query->where('platform_id', $platformId);
    }
}
