<?php

namespace App\Models;

use App\Services\ClientRetentionInsightService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
        'signup_source',
        'name',
        'phone_normalized',
        'email',
        'sb_user_id',
        'sb_matched_by',
        'city',
        'profile_status',
        'needs_payment',
        'notactive',
        'is_high_risk',
        'risk_reason_code',
        'risk_marked_at',
        'risk_marked_by',
        'premium',
        'premium_expire',
        'featured',
        'featured_expire',
        'escort_expire',
        'verified',
        'force_new',
        'new_badge_mode',
        'main_image_url',
        'display_image_url',
        'display_image_source',
        'display_image_checked_at',
        'wallet_balance',
        'wallet_currency',
        'wallet_last_synced_at',
        'assigned_to',
        'duplicate_of',
        'last_online_at',
        'last_synced_at',
        'wp_modified_at',
    ];

    protected $casts = [
        'needs_payment' => 'boolean',
        'notactive' => 'boolean',
        'premium' => 'boolean',
        'is_high_risk' => 'boolean',
        'featured' => 'boolean',
        'verified' => 'boolean',
        'force_new' => 'boolean',
        'wallet_balance' => 'decimal:2',
        'premium_expire' => 'integer',
        'featured_expire' => 'integer',
        'escort_expire' => 'integer',
        'duplicate_of' => 'integer',
        'last_online_at' => 'integer',
        'wallet_last_synced_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'wp_modified_at' => 'datetime',
        'risk_marked_at' => 'datetime',
        'display_image_checked_at' => 'datetime',
    ];

    protected $appends = [
        'wp_profile_url',
        'plan_key',
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

    public function riskMarkedBy()
    {
        return $this->belongsTo(User::class, 'risk_marked_by');
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
        return $query->where('profile_status', 'publish')
            ->where(function ($builder) {
                $builder->whereNull('needs_payment')->orWhere('needs_payment', false);
            })
            ->where(function ($builder) {
                $builder->whereNull('notactive')->orWhere('notactive', false);
            });
    }

    public function scopeNeedsPayment($query)
    {
        return $query->where('needs_payment', true);
    }

    public function scopeForPlatform($query, $platformId)
    {
        return $query->where('platform_id', $platformId);
    }

    public function scopeHighRisk($query)
    {
        return $query->where('is_high_risk', true);
    }

    public function scopeInactiveFor($query, int $days)
    {
        $threshold = now()->subDays($days)->timestamp;

        return $query->where(function ($builder) use ($threshold) {
            $builder->where('last_online_at', '<', $threshold)
                ->orWhereNull('last_online_at');
        });
    }

    public function scopeHasNoChat($query)
    {
        return $query->whereNull('sb_user_id');
    }

    public function scopeHasNoSubscriptionOrPayment($query)
    {
        return $query->whereDoesntHave('deals')
            ->whereDoesntHave('payments');
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

    public function getPlanKeyAttribute(): string
    {
        return $this->resolvePlanPresentation()['key'];
    }

    public function getPlanLabelAttribute(): string
    {
        return $this->resolvePlanPresentation()['label'];
    }

    public static function normalizePlanFilterKey(?string $value): string
    {
        $trimmed = trim((string) ($value ?? ''));
        if ($trimmed === '') {
            return '';
        }

        return static::normalizeKnownPlanKey($trimmed) ?? Str::slug($trimmed);
    }

    public static function planPresentationFromPackageValues(?string $tier, ?string $label, ?string $slug = null): ?array
    {
        $normalizedTier = trim((string) ($tier ?? ''));
        $normalizedLabel = trim((string) ($label ?? ''));
        $normalizedSlug = trim((string) ($slug ?? ''));

        $knownKey = static::normalizeKnownPlanKey($normalizedTier)
            ?? static::normalizeKnownPlanKey($normalizedLabel)
            ?? static::normalizeKnownPlanKey($normalizedSlug);

        if ($knownKey !== null) {
            return [
                'key' => $knownKey,
                'label' => static::labelForPlanKey($knownKey),
            ];
        }

        if ($normalizedSlug !== '') {
            return [
                'key' => Str::slug($normalizedSlug),
                'label' => static::humanizePlanLabel($normalizedLabel !== '' ? $normalizedLabel : $normalizedSlug),
            ];
        }

        if ($normalizedLabel !== '') {
            return [
                'key' => Str::slug($normalizedLabel),
                'label' => static::humanizePlanLabel($normalizedLabel),
            ];
        }

        if ($normalizedTier !== '' && strtolower($normalizedTier) !== 'custom') {
            return [
                'key' => Str::slug($normalizedTier),
                'label' => static::humanizePlanLabel($normalizedTier),
            ];
        }

        return null;
    }

    protected function resolvePlanPresentation(): array
    {
        $activeDealPresentation = $this->resolveActiveDealPlanPresentation();
        if ($activeDealPresentation !== null) {
            return $activeDealPresentation;
        }

        if ($this->premium) {
            return ['key' => 'premium', 'label' => 'Premium'];
        }

        if ($this->featured) {
            return ['key' => 'featured', 'label' => 'Featured'];
        }

        return ['key' => 'basic', 'label' => 'Basic'];
    }

    protected function resolveActiveDealPlanPresentation(): ?array
    {
        $activeDeal = $this->relationLoaded('activeDeal')
            ? $this->getRelation('activeDeal')
            : $this->activeDeal()->with('product')->first();

        if (!$activeDeal) {
            return null;
        }

        $product = $activeDeal->relationLoaded('product')
            ? $activeDeal->getRelation('product')
            : $activeDeal->product;

        if ($product) {
            $presentation = static::planPresentationFromPackageValues(
                $product->tier,
                $product->display_name ?: $product->name,
                $product->slug
            );

            if ($presentation !== null) {
                return $presentation;
            }
        }

        return static::planPresentationFromPackageValues($activeDeal->plan_type, $activeDeal->plan_type);
    }

    protected static function normalizeKnownPlanKey(?string $value): ?string
    {
        $normalized = Str::of((string) ($value ?? ''))
            ->lower()
            ->replace(['_', '-'], ' ')
            ->squish()
            ->value();

        if ($normalized === '' || $normalized === 'custom') {
            return null;
        }

        return match (true) {
            str_contains($normalized, 'vvip') => 'vvip',
            $normalized === 'vip' => 'vip',
            str_contains($normalized, 'premium') => 'premium',
            str_contains($normalized, 'featured') => 'featured',
            str_contains($normalized, 'basic') => 'basic',
            default => null,
        };
    }

    protected static function labelForPlanKey(string $key): string
    {
        return match ($key) {
            'vvip' => 'VVIP',
            'vip' => 'VIP',
            'premium' => 'Premium',
            'featured' => 'Featured',
            'basic' => 'Basic',
            default => static::humanizePlanLabel($key),
        };
    }

    protected static function humanizePlanLabel(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 'Basic';
        }

        if (in_array(strtolower($trimmed), ['vip', 'vvip'], true)) {
            return strtoupper($trimmed);
        }

        return Str::headline(str_replace(['_', '-'], ' ', $trimmed));
    }
}
