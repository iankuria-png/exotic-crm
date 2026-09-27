<?php

namespace App\Http\Controllers\CRM\Kyc;

use App\Http\Controllers\Controller;
use App\Models\KycAiReview;
use App\Models\KycSubject;
use App\Services\Kyc\Ai\KycAiReviewService;
use App\Services\MarketAuthorizationService;
use Illuminate\Http\Request;

class AiReviewController extends Controller
{
    public function __construct(private MarketAuthorizationService $markets, private KycAiReviewService $reviews) {}

    public function run(Request $request, KycSubject $subject)
    {
        $this->markets->ensureUserCanAccessPlatform($request->user(), (int) $subject->client->platform_id);
        $review = $this->reviews->queue($subject);
        abort_unless($review, 422, 'Automated review is switched off for this market.');

        return response()->json(['review_id' => $review->id, 'status' => $review->status], $review->status === 'skipped_cap' ? 429 : 202);
    }

    public function feedback(Request $request, KycSubject $subject)
    {
        $this->markets->ensureUserCanAccessPlatform($request->user(), (int) $subject->client->platform_id);
        $data = $request->validate(['review_id' => 'required|integer', 'note' => 'required|string|min:5|max:1000']);
        $review = KycAiReview::where('subject_id', $subject->id)->findOrFail($data['review_id']);
        abort_if($review->mode === 'shadow', 409, 'Shadow findings are reserved for calibration.');
        $review->update(['human_agreed' => false, 'feedback_note' => $data['note'], 'feedback_by' => $request->user()->id]);
        app(\App\Services\AuditService::class)->record(['platform_id' => (int) $subject->client->platform_id, 'actor_id' => $request->user()->id, 'action' => 'kyc.ai_feedback', 'entity_type' => 'kyc_ai_review', 'entity_id' => $review->id, 'reason' => $data['note']]);

        return response()->json(['success' => true]);
    }

    public function backlog(Request $request)
    {
        $data = $request->validate(['platform_id' => 'required|integer|exists:platforms,id']);
        $this->markets->ensureUserCanAccessPlatform($request->user(), $data['platform_id']);
        $count = 0;
        $skipped = 0;
        $subjects = KycSubject::where('status', 'in_review')->whereNotNull('ai_consent_at')->whereHas('client', fn ($q) => $q->where('platform_id', $data['platform_id']))->orderBy('submitted_at')->limit(100)->get();
        foreach ($subjects as $subject) {
            try {
                $review = $this->reviews->queue($subject, $count * 5);
                if ($review?->status === 'queued') {
                    $count++;
                } else {
                    $skipped++;
                }
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                if (! in_array($e->getStatusCode(), [409, 422, 429])) {
                    throw $e;
                }
                $skipped++;
            }
        }

        return response()->json(['queued' => $count, 'skipped' => $skipped]);
    }

    public function report(Request $request)
    {
        $data = $request->validate(['platform_id' => 'nullable|integer|exists:platforms,id']);
        $query = KycAiReview::where('created_at', '>=', now()->subDays(14));
        $consentRequired = KycSubject::where('status', 'in_review')->whereNull('ai_consent_at');
        $platformIds = $this->markets->resolveAccessiblePlatformIds($request->user());
        if (is_array($platformIds)) {
            $query->whereHas('subject.client', fn ($q) => $q->whereIn('platform_id', $platformIds));
            $consentRequired->whereHas('client', fn ($q) => $q->whereIn('platform_id', $platformIds));
        }
        if (! empty($data['platform_id'])) {
            $this->markets->ensureUserCanAccessPlatform($request->user(), $data['platform_id']);
            $query->whereHas('subject.client', fn ($q) => $q->where('platform_id', $data['platform_id']));
            $consentRequired->whereHas('client', fn ($q) => $q->where('platform_id', $data['platform_id']));
        }
        $models = (clone $query)->select('model')->selectRaw('COUNT(*) AS total, SUM(CASE WHEN human_agreed = 1 THEN 1 ELSE 0 END) AS agreed, SUM(CASE WHEN human_decision IS NOT NULL THEN 1 ELSE 0 END) AS compared, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS failed, SUM(CASE WHEN face_match = ? OR error = ? THEN 1 ELSE 0 END) AS declined', ['failed', 'declined', 'model_declined'])->groupBy('model')->get();

        return response()->json([
            'models' => $models, 'days' => 14,
            'provider_configured' => trim((string) config('services.seo_engine.openrouter.api_key')) !== '',
            'spent_today' => (float) KycAiReview::where('created_at', '>=', now()->startOfDay())->sum('cost_usd'),
            'reserved_today' => (float) KycAiReview::where('created_at', '>=', now()->startOfDay())->sum('reserved_usd'),
            'queued' => (clone $query)->whereIn('status', ['queued', 'running'])->count(),
            'consent_required' => $consentRequired->count(),
        ]);
    }
}
