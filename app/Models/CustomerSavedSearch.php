<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerSavedSearch extends Model
{
    public const MAX_PER_ACCOUNT = 50;

    protected $fillable = [
        'customer_account_id',
        'platform_id',
        'route_family',
        'route_value',
        'refinement_hash',
        'refinements_json',
        'label',
        'saved_at',
    ];

    protected $casts = [
        'customer_account_id' => 'integer',
        'platform_id' => 'integer',
        'refinements_json' => 'array',
        'saved_at' => 'datetime',
    ];

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }
}
