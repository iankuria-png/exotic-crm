<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerActivityEvent extends Model
{
    public const RETENTION_DAYS = 180;

    public const EVENT_WORKSPACE_VIEWED = 'workspace.viewed';
    public const EVENT_SAVE_ADDED = 'save.added';
    public const EVENT_SAVE_REMOVED = 'save.removed';
    public const EVENT_SAVES_MERGED = 'save.merged';
    public const EVENT_ACCOUNT_LINKED = 'account.linked';

    // Phase 3. Note that an individual profile view does NOT emit an event:
    // `customer_recent_views` is itself the record, and one event per page view
    // would flood a 180-day table. Only the destructive actions are logged.
    public const EVENT_VIEWS_CLEARED = 'view.cleared';
    public const EVENT_COMPARE_ADDED = 'compare.added';
    public const EVENT_COMPARE_REMOVED = 'compare.removed';
    public const EVENT_COMPARE_CLEARED = 'compare.cleared';

    protected $fillable = [
        'customer_account_id',
        'platform_id',
        'event_type',
        'object_type',
        'object_ref',
        'occurred_at',
        'context_json',
    ];

    protected $casts = [
        'customer_account_id' => 'integer',
        'platform_id' => 'integer',
        'object_ref' => 'integer',
        'occurred_at' => 'datetime',
        'context_json' => 'array',
    ];

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }
}
