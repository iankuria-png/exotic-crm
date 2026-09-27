<?php

namespace App\Jobs\Kyc;

use App\Models\KycAiReview;
use App\Models\KycSubject;
use App\Services\Kyc\Ai\KycAiPolicy;
use App\Services\Kyc\Ai\KycVisionClient;
use App\Services\Kyc\KycSettingsService;
use App\Services\Kyc\KycSubjectService;
use App\Support\KycTranslationCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class RunKycAiReviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $reviewId) {}

    public function handle(KycVisionClient $vision, KycAiPolicy $policy, KycSettingsService $settingsService): void
    {
        if (! KycAiReview::whereKey($this->reviewId)->where('status', 'queued')->update(['status' => 'running', 'updated_at' => now()])) {
            return;
        }
        $review = KycAiReview::findOrFail($this->reviewId);
        $started = microtime(true);
        try {
            $subject = $review->subject;
            $settings = $settingsService->aiSettings((int) $subject->client->platform_id);
            if (! $review->created_at->isToday() || $settings['mode'] === 'off' || ! $this->current($review, $subject)) {
                $review->update(['status' => 'cancelled', 'reserved_usd' => 0, 'error' => 'disabled_or_superseded', 'completed_at' => now()]);

                return;
            }
            $used = (float) KycAiReview::where('created_at', '>=', now()->startOfDay())->sum(DB::raw('cost_usd + reserved_usd'));
            if ($used > (float) $settings['daily_cap_usd']) {
                $review->update(['status' => 'skipped_cap', 'reserved_usd' => 0, 'completed_at' => now()]);

                return;
            }
            $observations = null;
            $hadFailure = false;
            foreach (array_slice($settings['ladder'], 0, 2) as $index => $model) {
                try {
                    $review->update(['model' => $model, 'fallback_used' => $index > 0]);
                    $observations = $vision->inspect($review, $model);
                    $review->update(['model' => $model, 'fallback_used' => $index > 0]);
                    break;
                } catch (\Throwable $e) {
                    $hadFailure = true;
                    // A refusal or malformed answer is not retried to evade a provider's decision.
                    if (in_array($e->getMessage(), ['model_declined', 'invalid_observation_schema', 'incomplete_response'])) {
                        throw $e;
                    }
                }
            }
            if (! $observations) {
                throw new \RuntimeException('provider_unavailable');
            }
            $second = null;
            if ($observations['face_match'] === 'likely_different' && $settings['reject_requires_second_opinion']) {
                if ($settings['second_opinion_model'] === $review->model) {
                    throw new \RuntimeException('independent_model_required');
                }
                $second = $vision->inspect($review, $settings['second_opinion_model']);
                $review->update(['second_opinion_model' => $settings['second_opinion_model'], 'second_opinion' => $second]);
            }
            // Uploaded stills or duplicate pose frames never establish presence for automatic approval.
            $frames = $subject->documents()->whereIn('id', $review->document_ids)->where('kind', 'selfie')->get();
            if ($frames->count() !== 3 || $frames->pluck('sha256')->unique()->count() !== 3) {
                $observations['poses_consistent'] = false;
            }
            $decision = $policy->decide($observations, $settings, $second);
            DB::transaction(function () use ($review, $observations, $decision, $settingsService, $policy, $started, $hadFailure) {
                $subject = KycSubject::query()->lockForUpdate()->findOrFail($review->subject_id);
                $settings = $settingsService->aiSettings((int) $subject->client->platform_id);
                $apply = $this->current($review, $subject) && $settings['mode'] === $review->mode && $settings['mode'] !== 'off';
                // Re-evaluate thresholds and reject toggles at application time.
                $decision = $policy->decide($observations, $settings, $review->second_opinion);
                $action = $apply ? $policy->action($review->mode, $decision['recommendation'], $settings) : 'none';
                $review->refresh()->fill([
                    'status' => 'completed', 'observations' => $observations,
                    'face_match' => $observations['face_match'], 'face_match_confidence' => $observations['face_match_confidence'],
                    'recommendation' => $decision['recommendation'], 'reason_codes' => $decision['reasons'], 'retake' => $decision['retake'],
                    'action_taken' => $action, 'error' => $apply ? null : 'superseded',
                    'reserved_usd' => $hadFailure ? max(0, 1 - $review->cost_usd) : ($review->cost_usd > 0 ? 0 : 1),
                    'qa_sample' => $action === 'approved' && random_int(1, 100) <= $settings['qa_sample_pct'],
                    'latency_ms' => (int) ((microtime(true) - $started) * 1000), 'completed_at' => now(),
                ]);
                if ($review->human_decision) {
                    $review->human_agreed = $review->human_decision === $decision['recommendation'];
                }
                $review->save();
                app(\App\Services\AuditService::class)->record(['platform_id' => (int) $subject->client->platform_id, 'action' => 'kyc.ai_review_completed', 'entity_type' => 'kyc_ai_review', 'entity_id' => $review->id, 'after_state' => ['mode' => $review->mode, 'action' => $action, 'recommendation' => $review->recommendation], 'reason' => 'Automated review of the consented document set']);
                if (! $apply) {
                    return;
                }
                // Shadow findings must not leak into the human review through extracted fields.
                if ($review->mode !== 'shadow' && ! in_array('dob_invalid', $decision['reasons'], true)) {
                    $subject->fill(['legal_name' => $observations['legal_name'], 'nationality' => $observations['nationality']]);
                    if ($observations['dob'] && ! array_intersect(['dob_unreadable', 'dob_invalid', 'model_declined'], $decision['reasons'])) {
                        $subject->dob = $observations['dob'];
                    }
                    $subject->save();
                }
                $service = app(KycSubjectService::class);
                if ($action === 'approved') {
                    $service->markApprovedFromSource($subject, 'kyc', null, 'Automated verification completed');
                }
                if ($action === 'info_requested' || $action === 'rejected') {
                    $message = KycTranslationCatalog::aiMessage($action, $decision['retake']);
                    $review->update(['advertiser_message' => $message]);
                    if ($action === 'info_requested') {
                        $service->requestInfo($subject, $message, 'Automated photo-quality check');
                    } else {
                        $service->reject($subject, $message, 'Two-stage automated identity check');
                    }
                }
            });
        } catch (\Throwable $e) {
            $review->refresh()->update(['status' => 'failed', 'error' => in_array($e->getMessage(), ['model_declined', 'invalid_observation_schema', 'incomplete_response', 'provider_not_configured', 'unsupported_document_format', 'invalid_image']) ? $e->getMessage() : 'automated_check_unavailable', 'latency_ms' => (int) ((microtime(true) - $started) * 1000), 'completed_at' => now(), 'reserved_usd' => max(0, 1 - $review->cost_usd)]);
        }
    }

    private function current(KycAiReview $review, KycSubject $subject): bool
    {
        return (int) $subject->ai_last_review_id === (int) $review->id
            && (int) $subject->review_version === (int) $review->review_version
            && $subject->status === 'in_review'
            && $review->document_ids === $subject->documents()->whereIn('id', $review->document_ids)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function failed(?\Throwable $exception): void
    {
        KycAiReview::whereKey($this->reviewId)->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'error' => 'worker_timeout', 'completed_at' => now()]);
    }
}
