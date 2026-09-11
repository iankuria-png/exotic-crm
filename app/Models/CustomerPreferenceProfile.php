<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPreferenceProfile extends Model
{
    protected $fillable = [
        'customer_account_id',
        'platform_id',
        'facet_weights_json',
        'signal_count',
        'positive_signal_count',
        'last_signal_at',
        'last_rebuilt_at',
        'reset_at',
    ];

    protected $casts = [
        'customer_account_id' => 'integer',
        'platform_id' => 'integer',
        'facet_weights_json' => 'array',
        'signal_count' => 'integer',
        'positive_signal_count' => 'integer',
        'last_signal_at' => 'datetime',
        'last_rebuilt_at' => 'datetime',
        'reset_at' => 'datetime',
    ];

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }
}
