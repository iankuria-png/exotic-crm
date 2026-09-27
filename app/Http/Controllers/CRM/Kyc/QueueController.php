<?php

namespace App\Http\Controllers\CRM\Kyc;

use App\Http\Controllers\Controller;
use App\Models\KycSubject;
use App\Services\Kyc\KycSettingsService;
use App\Services\MarketAuthorizationService;
use Illuminate\Http\Request;

class QueueController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly KycSettingsService $settingsService,
    ) {}

    public function index(Request $request)
    {
        $query = KycSubject::query()->with(['client.platform', 'client.activeDeal.product', 'sites', 'aiLastReview'])
            ->whereHas('client', fn ($builder) => $builder->where('kyc_required', true));

        $platformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());
        if (is_array($platformIds)) {
            $query->whereHas('client', fn ($builder) => $builder->whereIn('platform_id', $platformIds ?: [0]));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('platform_id')) {
            $platformId = (int) $request->input('platform_id');
            $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), $platformId);
            $query->whereHas('client', fn ($builder) => $builder->where('platform_id', $platformId));
        }

        $age = (int) $request->input('age_days', 0);
        if (in_array($age, [1, 3, 7, 14], true)) {
            $query->where('updated_at', '<=', now()->subDays($age));
        }
        $filter = $request->input('ai_filter');
        if ($filter === 'needs_human') {
            $query->where('status', 'in_review')->where(function ($q) {
                $q->whereDoesntHave('aiLastReview')->orWhereHas('aiLastReview', fn ($a) => $a->where(function ($r) {
                    $r->whereIn('mode', ['shadow', 'advisory'])
                        ->orWhereNotIn('status', ['queued', 'running'])
                        ->orWhere('updated_at', '<', now()->subMinutes(10));
                }));
            });
        } elseif ($filter === 'qa') {
            $query->whereHas('aiLastReview', fn ($a) => $a->where('qa_sample', true)->whereNull('human_decision'));
        } elseif ($filter === 'retake') {
            $query->where('status', 'info_requested')->whereHas('aiLastReview', fn ($a) => $a->where('action_taken', 'info_requested'));
        }
        $sort = (string) $request->input('sort', 'oldest_in_review');
        if ($sort === 'ai_flagged') {
            $query->orderByRaw("CASE WHEN EXISTS (SELECT 1 FROM kyc_ai_reviews ar WHERE ar.id = kyc_subjects.ai_last_review_id AND ar.mode != 'shadow' AND ar.recommendation = 'human_urgent') THEN 0 ELSE 1 END");
        }
        if ($sort === 'overdue') {
            $query->orderBy('grace_started_at')->orderBy('updated_at');
        } else {
            $query->orderByRaw("CASE WHEN status = 'in_review' THEN 0 WHEN status = 'info_requested' THEN 1 ELSE 2 END")
                ->orderBy('updated_at');
        }

        $page = $query->paginate(min(100, max(1, (int) $request->input('per_page', 25))));
        $page->getCollection()->transform(function ($subject) {
            $finding = app(\App\Services\Kyc\Ai\KycAiReviewService::class)->findings($subject);
            $subject->unsetRelation('aiLastReview');
            $subject->setAttribute('ai_review', $finding ? \Illuminate\Support\Arr::only($finding, ['id', 'status', 'mode', 'recommendation', 'qa_sample', 'error', 'stale']) : null);

            return $subject;
        });

        return response()->json($page);
    }

    public function count(Request $request)
    {
        return response()->json($this->settingsService->queueCountForUser($request->user()));
    }
}
