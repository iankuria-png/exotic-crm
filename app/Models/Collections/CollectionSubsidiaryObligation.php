<?php

namespace App\Models\Collections;

use Illuminate\Database\Eloquent\Model;

class CollectionSubsidiaryObligation extends Model
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['version' => 'integer', 'source_generation' => 'integer', 'interval' => 'array', 'target_identity' => 'encrypted:array',
        'policy_snapshot' => 'array', 'setup_options' => 'array', 'completed_at' => 'datetime'];

    protected $hidden = ['target_identity', 'setup_options'];

    public function allocation()
    {
        return $this->belongsTo(CollectionAllocation::class, 'allocation_id');
    }
}
