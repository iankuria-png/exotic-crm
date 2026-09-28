<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KycAiReview extends Model
{
    protected $guarded = [];

    protected $casts = [
        'document_ids' => 'array', 'observations' => 'array', 'second_opinion' => 'array',
        'reason_codes' => 'array', 'retake' => 'array', 'fallback_used' => 'boolean',
        'qa_sample' => 'boolean', 'human_agreed' => 'boolean', 'completed_at' => 'datetime',
        'face_match_confidence' => 'float', 'cost_usd' => 'float', 'reserved_usd' => 'float',
    ];

    public function subject()
    {
        return $this->belongsTo(KycSubject::class, 'subject_id');
    }

    public function events()
    {
        return $this->hasMany(KycReviewEvent::class, 'ai_review_id');
    }
}
