<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCompareItem extends Model
{
    public const TYPE_PROFILE = 'profile';

    protected $fillable = [
        'compare_set_id',
        'customer_account_id',
        'platform_id',
        'object_type',
        'object_ref',
        'position',
        'added_at',
    ];

    protected $casts = [
        'compare_set_id' => 'integer',
        'customer_account_id' => 'integer',
        'platform_id' => 'integer',
        'object_ref' => 'integer',
        'position' => 'integer',
        'added_at' => 'datetime',
    ];

    public function compareSet(): BelongsTo
    {
        return $this->belongsTo(CustomerCompareSet::class, 'compare_set_id');
    }
}
