<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerSavedObject extends Model
{
    public const TYPE_PROFILE = 'profile';

    public const SOURCE_WORKSPACE = 'my_exotic';
    public const SOURCE_LEGACY_BACKFILL = 'wp_legacy_backfill';
    public const SOURCE_LOCAL_MERGE = 'browser_local_merge';

    protected $fillable = [
        'customer_account_id',
        'platform_id',
        'object_type',
        'object_ref',
        'source',
        'saved_at',
    ];

    protected $casts = [
        'customer_account_id' => 'integer',
        'platform_id' => 'integer',
        'object_ref' => 'integer',
        'saved_at' => 'datetime',
    ];

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }
}
