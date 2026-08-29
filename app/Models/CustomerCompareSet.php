<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The one active compare set a member holds. Named, multi-set comparison was
 * deliberately not modelled.
 */
class CustomerCompareSet extends Model
{
    /** Signed Phase 0B policy: compare sets expire 30 days after last update. */
    public const RETENTION_DAYS = 30;

    protected $fillable = [
        'customer_account_id',
        'platform_id',
        'last_activity_at',
    ];

    protected $casts = [
        'customer_account_id' => 'integer',
        'platform_id' => 'integer',
        'last_activity_at' => 'datetime',
    ];

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomerCompareItem::class, 'compare_set_id');
    }
}
