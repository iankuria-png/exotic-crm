<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A profile a signed-in member has opened. Signed-out visitors are not recorded
 * here — they keep the existing browser-local cookie.
 */
class CustomerRecentView extends Model
{
    public const TYPE_PROFILE = 'profile';

    /**
     * Recent views were not named in the signed Phase 0B retention table. This
     * matches the activity-event window until Ian signs a different number.
     */
    public const RETENTION_DAYS = 180;

    /** Rows kept per member. Older views beyond this are trimmed on write. */
    public const MAX_PER_ACCOUNT = 200;

    protected $fillable = [
        'customer_account_id',
        'platform_id',
        'object_type',
        'object_ref',
        'view_count',
        'view_seq',
        'first_viewed_at',
        'last_viewed_at',
    ];

    protected $casts = [
        'customer_account_id' => 'integer',
        'platform_id' => 'integer',
        'object_ref' => 'integer',
        'view_count' => 'integer',
        'view_seq' => 'integer',
        'first_viewed_at' => 'datetime',
        'last_viewed_at' => 'datetime',
    ];

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }
}
