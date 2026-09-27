<?php

namespace App\Services\Kyc\Ai;

use App\Jobs\Kyc\RunKycAiReviewJob;
use App\Models\KycAiReview;
use App\Models\KycSubject;
use App\Services\Kyc\KycSettingsService;
use Illuminate\Support\Facades\DB;

class KycAiReviewService
{
    public function submit(KycSubject $subject, array $payload): array
    {
        return DB::transaction(function () use ($subject, $payload) {
            $subject = KycSubject::query()->lockForUpdate()->findOrFail($subject->id);
            $settings = app(KycSettingsService::class);
            abort_unless($settings->isPlatformEnabled((int) $subject->client->platform_id), 422, 'Verification is not enabled for this market.');
            abort_unless(in_array($payload['document_type'], $settings->documentTypes((int) $subject->client->platform_id), true), 422, 'This document type is not accepted in your market.');
            abort_unless($subject->capture_set_id === $payload['capture_set_id'], 409, 'This capture session has been replaced. Start again.');
            $docs = $subject->documents()->where('capture_set_id', $payload['capture_set_id'])->where('document_type', $payload['document_type'])->orderBy('id')->get();
            $ids = $docs->pluck('id')->map(fn ($id) => (int) $id)->all();
            $provided = array_map('intval', $payload['document_ids']);
            sort($provided);
            abort_unless($ids === $provided, 409, 'Your photos changed. Check them before submitting again.');
            $required = $payload['document_type'] === 'passport' ? ['id_front', 'selfie'] : ['id_front', 'id_back', 'selfie'];
            foreach ($required as $kind) {
                abort_unless($docs->contains(fn ($d) => $d->kind === $kind && (int) $d->sequence === 0), 422, 'Please add all required photos.');
            }
            if ($subject->submitted_document_ids === $ids && $subject->submitted_at) {
                return ['subject_id' => $subject->id, 'status' => $subject->status, 'ai_review' => $subject->aiLastReview?->status ?? 'disabled'];
            }
            $subject->forceFill([
                'document_type' => $payload['document_type'], 'submitted_document_ids' => $ids,
                'submitted_at' => now(), 'ai_consent_at' => now(), 'consent_version' => 'kyc-guided-v1',
                'status' => 'in_review', 'last_reason_user' => null, 'last_reason_internal' => null,
                'review_version' => $subject->review_version + 1,
            ])->save();
            $review = $this->queue($subject);

            return ['subject_id' => $subject->id, 'status' => 'in_review', 'ai_review' => $review?->status ?? 'disabled'];
        });
    }

    public function queue(KycSubject $subject, int $delaySeconds = 0): ?KycAiReview
    {
        return DB::transaction(function () use ($subject, $delaySeconds) {
            $subject = KycSubject::query()->lockForUpdate()->findOrFail($subject->id);
            $settingsService = app(KycSettingsService::class);
            $settings = $settingsService->aiSettings((int) $subject->client->platform_id);
            if ($settings['mode'] === 'off') {
                return null;
            }
            abort_unless($subject->ai_consent_at && $subject->submitted_at && $subject->submitted_document_ids, 422, 'A completed submission with automated-review consent is required.');
            abort_unless($subject->status === 'in_review', 409, 'Only submissions waiting for review can be analysed.');
            $ids = $subject->documents()->whereIn('id', $subject->submitted_document_ids)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
            abort_unless($ids === $subject->submitted_document_ids, 409, 'Photos changed after submission. Request a fresh submission.');
            $active = $subject->aiLastReview;
            if ($active && in_array($active->status, ['queued', 'running']) && ($active->document_ids !== $ids || (int) $active->review_version !== (int) $subject->review_version)) {
                $active->update(['status' => 'cancelled', 'error' => 'superseded', 'completed_at' => now(), 'reserved_usd' => $active->status === 'queued' ? 0 : $active->reserved_usd]);
            }
            if ($active && in_array($active->status, ['queued', 'running']) && $active->updated_at->gt(now()->subMinutes(10))) {
                abort(409, 'An automated check is already running.');
            }
            if ($active && in_array($active->status, ['queued', 'running'])) {
                $active->update(['status' => 'failed', 'error' => 'worker_timeout', 'completed_at' => now()]);
            }
            abort_if(KycAiReview::where('subject_id', $subject->id)->where('created_at', '>', now()->subHour())->count() >= 5, 429, 'Please wait before running another check.');
            // Serialize reservations across workers and markets, not just per subject.
            $settingsService->get()->newQuery()->lockForUpdate()->findOrFail(config('kyc.settings_id', 1));
            $used = (float) KycAiReview::where('created_at', '>=', now()->startOfDay())->sum(DB::raw('cost_usd + reserved_usd'));
            $capReached = $used + 1 > (float) $settings['daily_cap_usd'];
            $review = KycAiReview::create([
                'subject_id' => $subject->id, 'document_ids' => $ids, 'review_version' => $subject->review_version,
                'mode' => $settings['mode'], 'status' => $capReached ? 'skipped_cap' : 'queued',
                'reserved_usd' => $capReached ? 0 : 1,
                'reason_codes' => $capReached ? ['daily_cap'] : [],
            ]);
            $subject->update(['ai_last_review_id' => $review->id]);
            if (! $capReached) {
                RunKycAiReviewJob::dispatch($review->id)->onQueue('kyc-ai')->delay(now()->addSeconds(max(0, $delaySeconds)))->afterCommit();
            }

            return $review;
        });
    }

    public function publicStatus(KycSubject $subject): array
    {
        $review = $subject->aiLastReview;
        $settings = app(KycSettingsService::class);
        $checking = $review && in_array($review->status, ['queued', 'running']) && $review->updated_at->gt(now()->subMinutes(10));

        return [
            'flow_version' => 1,
            'enabled' => $settings->isPlatformEnabled((int) $subject->client->platform_id),
            'document_types' => $settings->documentTypes((int) $subject->client->platform_id),
            'document_type' => $subject->document_type,
            'capture_set_id' => $subject->capture_set_id,
            'documents' => $subject->documents()->where('capture_set_id', $subject->capture_set_id)->get(['id', 'kind', 'sequence', 'document_type'])->toArray(),
            'ai' => $checking ? 'checking' : ($review ? 'done' : 'off'),
            'retake' => $subject->status === 'info_requested' && $review?->action_taken === 'info_requested' ? ($review->retake ?? []) : [],
            'message' => $subject->last_reason_user,
        ];
    }

    public function recordHumanDecision(KycSubject $subject, string $decision): void
    {
        $review = $subject->aiLastReview;
        if (! $review || $review->document_ids !== $subject->submitted_document_ids) {
            return;
        }
        $review->update([
            'human_decision' => $decision,
            'human_agreed' => $review->status === 'completed' ? $review->recommendation === $decision : null,
        ]);
    }

    public function findings(KycSubject $subject): ?array
    {
        $review = $subject->aiLastReview;
        if (! $review) {
            return null;
        }
        if ($review->mode === 'shadow') {
            return ['id' => $review->id, 'mode' => 'shadow', 'status' => $review->status];
        }
        $data = $review->toArray();
        $data['stale'] = $review->document_ids !== $subject->documents()->whereIn('id', $review->document_ids)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (in_array($review->status, ['queued', 'running']) && $review->updated_at->lt(now()->subMinutes(10))) {
            $data['status'] = 'stalled';
        }

        return $data;
    }
}
