<?php

namespace App\Models;

use App\Services\ClientRetentionInsightService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(function (Client $client): void {
            ClientRetentionInsightService::scheduleRefreshForClientId((int) $client->id);
        });

        static::deleted(function (Client $client): void {
            ClientRetentionInsight::query()->where('client_id', (int) $client->id)->delete();
        });
    }

    protected $fillable = [
        'platform_id',
        'wp_post_id',
        'wp_user_id',
        'client_type',
        'name',
        'phone_normalized',
        'email',
        'sb_user_id',
        'sb_matched_by',
        'city',
        'profile_status',
        'premium',
        'premium_expire',
        'featured',
        'featured_expire',
        'escort_expire',
        'verified',
        'main_image_url',
        'wallet_balance',
        'wallet_currency',
        'wallet_last_synced_at',
        'assigned_to',
        'duplicate_of',
        'last_online_at',
        'last_synced_at',
    ];

    protected $casts = [
        'premium' => 'boolean',
        'featured' => 'boolean',
        'verified' => 'boolean',
        'wallet_balance' => 'decimal:2',
        'premium_expire' => 'integer',
        'featured_expire' => 'integer',
        'escort_expire' => 'integer',
        'duplicate_of' => 'integer',
        'last_online_at' => 'integer',
        'wallet_last_synced_at' => 'datetime',
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

    public function retentionInsight()
    {
        return $this->hasOne(ClientRetentionInsight::class);
    }

    public function walletTransactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function credentialDispatches()
    {
        return $this->hasMany(ClientCredentialDispatch::class);
    }

    public function duplicateParent()
    {
        return $this->belongsTo(self::class, 'duplicate_of');
    }

    public function duplicates()
    {
        return $this->hasMany(self::class, 'duplicate_of');
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
