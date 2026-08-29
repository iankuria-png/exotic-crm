<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerFollow extends Model
{
    public const TYPE_PROFILE = 'profile';
    public const TYPE_LOCATION = 'location';

    public const SOURCE_WORKSPACE = 'my_exotic';

    protected $fillable = [
        'customer_account_id',
        'platform_id',
        'follow_type',
        'object_ref',
        'source',
        'followed_at',
    ];

    protected $casts = [
        'customer_account_id' => 'integer',
        'platform_id' => 'integer',
        'object_ref' => 'integer',
        'followed_at' => 'datetime',
    ];

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }
}
