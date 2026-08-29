<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerUnlockClaim extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    public const SOURCE_LOGGED_IN_REVEAL = 'logged_in_reveal';
    public const SOURCE_POST_UNLOCK_ACCOUNT = 'post_unlock_account';

    protected $fillable = [
        'customer_account_id',
        'platform_id',
        'visitor_contact_unlock_id',
        'wp_post_id',
        'client_id',
        'scope',
        'status',
        'claimed_at',
        'expires_at',
        'last_revealed_at',
        'source',
    ];

    protected $casts = [
        'customer_account_id' => 'integer',
        'platform_id' => 'integer',
        'visitor_contact_unlock_id' => 'integer',
        'wp_post_id' => 'integer',
        'client_id' => 'integer',
        'claimed_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_revealed_at' => 'datetime',
    ];

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function visitorUnlock(): BelongsTo
    {
        return $this->belongsTo(VisitorContactUnlock::class, 'visitor_contact_unlock_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function reachabilityFeedback(): HasMany
    {
        return $this->hasMany(CustomerReachabilityFeedback::class);
    }
}
