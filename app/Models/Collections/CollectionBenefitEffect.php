<?php

namespace App\Models\Collections;

use Illuminate\Database\Eloquent\Model;

class CollectionBenefitEffect extends Model
{
    protected $table = 'collection_benefit_effects';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['before_state' => 'array', 'contribution' => 'array', 'target_state' => 'array', 'verified_state' => 'array', 'dispatch_started_at' => 'datetime', 'verified_at' => 'datetime', 'superseded_at' => 'datetime'];

    protected $hidden = [];
}
