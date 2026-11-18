<?php

namespace App\Models\Collections;

use Illuminate\Database\Eloquent\Model;

class CollectionMatchFeedback extends Model
{
    protected $table = 'collection_match_feedback';

    protected $guarded = [];

    protected $casts = ['agreed' => 'boolean', 'suggested_rules' => 'array', 'suggested_score' => 'integer'];
}
