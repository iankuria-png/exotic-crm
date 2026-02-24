<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_id',
        'wp_post_id',
        'wp_user_id',
        'client_type',
        'name',
        'phone_normalized',
        'email',
        'city',
        'profile_status',
        'premium',
        'premium_expire',
        'featured',
        'featured_expire',
        'escort_expire',
        'verified',
        'main_image_url',
        'assigned_to',
        'last_synced_at',
    ];

    protected $casts = [
        'premium' => 'boolean',
        'featured' => 'boolean',
        'verified' => 'boolean',
        'premium_expire' => 'datetime',
        'featured_expire' => 'datetime',
        'escort_expire' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    protected $appends = [
        'wp_profile_url',
        'plan_label',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function assignedAgent()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function deals()
    {
        return $this->hasMany(Deal::class);
    }

    public function leads()
    {
        return $this->hasMany(Lead::class, 'converted_client_id');
    }

    public function notes()
    {
        return $this->hasMany(ClientNote::class);
    }

    public function timelineEvents()
    {
        return $this->morphMany(TimelineEvent::class, 'entity', 'entity_type', 'entity_id')
            ->where('entity_type', 'client');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function activeDeal()
    {
        return $this->hasOne(Deal::class)->where('status', 'active')->latest();
    }

    public function scopeActive($query)
    {
        return $query->where('profile_status', 'publish');
    }

    public function scopeNeedsPayment($query)
    {
        return $query->where('profile_status', 'private');
    }

    public function scopeForPlatform($query, $platformId)
    {
        return $query->where('platform_id', $platformId);
    }

    public function getWpProfileUrlAttribute(): ?string
    {
        $wpPostId = (int) ($this->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            return null;
        }

        $apiUrl = $this->platform?->wp_api_url;
        if (!$apiUrl) {
            return null;
        }

        $baseUrl = preg_replace('#/wp-json/.*$#', '', (string) $apiUrl);
        $baseUrl = rtrim((string) $baseUrl, '/');
        if ($baseUrl === '') {
            return null;
        }

        return "{$baseUrl}/?p={$wpPostId}";
    }

    public function getPlanLabelAttribute(): string
    {
        if ($this->premium) {
            return 'Premium';
        }

        if ($this->featured) {
            return 'Featured';
        }

        return 'Basic';
    }
}
