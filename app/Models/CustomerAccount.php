<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A logged-in WordPress member with a My Exotic workspace.
 *
 * Not a `visitor_contact_unlocks` row (anonymous unlock buyer, no account) and
 * not a `Client` (advertiser). Identity is (platform_id, wp_user_id) only.
 */
class CustomerAccount extends Model
{
    protected $fillable = [
        'platform_id',
        'wp_user_id',
        'display_name',
        'email',
        'email_hash',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'platform_id' => 'integer',
        'wp_user_id' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function savedObjects(): HasMany
    {
        return $this->hasMany(CustomerSavedObject::class);
    }

    public function activityEvents(): HasMany
    {
        return $this->hasMany(CustomerActivityEvent::class);
    }

    public function follows(): HasMany
    {
        return $this->hasMany(CustomerFollow::class);
    }

    public function savedSearches(): HasMany
    {
        return $this->hasMany(CustomerSavedSearch::class);
    }

    public function unlockClaims(): HasMany
    {
        return $this->hasMany(CustomerUnlockClaim::class);
    }

    public static function hashEmail(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return $email === '' ? null : hash('sha256', $email);
    }
}
