<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KycReviewEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function subject()
    {
        return $this->belongsTo(KycSubject::class, 'subject_id');
    }

    public function aiReview()
    {
        return $this->belongsTo(KycAiReview::class, 'ai_review_id');
    }
}
