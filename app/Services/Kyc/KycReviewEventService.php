<?php

namespace App\Services\Kyc;

use App\Models\KycAiReview;
use App\Models\KycReviewEvent;
use App\Models\KycSubject;

class KycReviewEventService
{
    /**
     * Store only operational facts: no image bytes, prompts, provider response bodies,
     * document numbers, or inferred identity data belongs in this timeline.
     */
    public function record(KycSubject $subject, string $event, string $summary, array $metadata = [], ?KycAiReview $review = null, string $level = 'info'): KycReviewEvent
    {
        return KycReviewEvent::create([
            'subject_id' => $subject->id,
            'ai_review_id' => $review?->id,
            'event' => $event,
            'level' => $level,
            'summary' => $summary,
            'metadata' => $metadata ?: null,
            'occurred_at' => now(),
        ]);
    }

    public function documentSet(KycSubject $subject, array $documentIds): array
    {
        return $subject->documents()->whereIn('id', $documentIds)->orderBy('kind')->orderBy('sequence')->get()
            ->map(fn ($document) => [
                'id' => (int) $document->id,
                'kind' => $document->kind,
                'sequence' => (int) $document->sequence,
                'capture_set_id' => $document->capture_set_id,
            ])->values()->all();
    }
}
