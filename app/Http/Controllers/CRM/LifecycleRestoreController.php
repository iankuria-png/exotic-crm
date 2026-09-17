<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Jobs\RunLifecycleRestoreJob;
use App\Models\Client;
use App\Models\LifecycleRestoreRun;
use App\Models\Platform;
use App\Services\ClientLifecycleMutationLock;
use App\Services\FeatureSettingsService;
use App\Services\MarketAuthorizationService;
use App\Services\ProfileLifecycleRestoreService;
use App\Support\ClientLifecycleState;
use App\Support\LifecycleRestoreEligibility;
use App\Support\LifecycleRestorePacing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SEO Recovery endpoints. Republishing content is an admin operation — these
 * routes are restricted to admin/sub_admin in routes/api.php.
 */
class LifecycleRestoreController extends Controller
{
    public function __construct(
        private readonly ProfileLifecycleRestoreService $restorer,
        private readonly MarketAuthorizationService $marketAuth,
        private readonly FeatureSettingsService $settings,
        private readonly ClientLifecycleMutationLock $lifecycleLock,
    ) {}

    /**
     * Candidate count + sample for a configuration. No writes — this is what
     * the Configure panel reads on every filter change.
     */
    public function eligibility(Request $request): JsonResponse
    {
        $platform = $this->authorizedPlatform($request);

        if ($platform instanceof JsonResponse) {
            return $platform;
        }

        $filters = $this->validatedFilters($request);

        // A throwaway run: preview() takes its config from a run, and building
        // one unsaved keeps a single code path for preview and execute.
        $run = new LifecycleRestoreRun([
            'platform_id' => $platform->id,
            'mode' => LifecycleRestoreRun::MODE_DRY,
            'target_state' => $this->validatedTargetState($request),
            'batch_limit' => $this->validatedLimit($request),
            'filters' => $filters,
        ]);
        $run->platform_id = $platform->id;

        $preview = $this->restorer->preview($run);

        return response()->json([
            'platform' => [
                'id' => (int) $platform->id,
                'name' => $platform->name,
                'lifecycle_enabled' => $platform->lifecycleEnabled(),
            ],
            'already_restored' => (int) Client::query()
                ->where('platform_id', $platform->id)
                ->whereNotNull('lifecycle_restored_at')
                ->count(),
            'still_offline' => (int) Client::query()
                ->where('platform_id', $platform->id)
                ->where('profile_status', 'private')
                ->whereNotNull('wp_post_id')
                ->count(),
            'pacing' => $this->pacingFor((int) $platform->id),
        ] + $preview);
    }

    /** Run history for a market. */
    public function index(Request $request): JsonResponse
    {
        $platform = $this->authorizedPlatform($request);

        if ($platform instanceof JsonResponse) {
            return $platform;
        }

        $runs = LifecycleRestoreRun::query()
            ->where('platform_id', $platform->id)
            ->with('requester:id,name')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (LifecycleRestoreRun $run) => $this->presentRun($run));

        return response()->json(['data' => $runs]);
    }

    /** Create a run and dispatch it. */
    public function store(Request $request): JsonResponse
    {
        $platform = $this->authorizedPlatform($request);

        if ($platform instanceof JsonResponse) {
            return $platform;
        }

        if (! $platform->lifecycleEnabled()) {
            return response()->json([
                'message' => 'This market does not have the profile lifecycle enabled. Turn it on in Settings first.',
            ], 422);
        }

        $mode = $request->input('mode') === LifecycleRestoreRun::MODE_LIVE
            ? LifecycleRestoreRun::MODE_LIVE
            : LifecycleRestoreRun::MODE_DRY;

        $run = LifecycleRestoreRun::create([
            'platform_id' => (int) $platform->id,
            'requested_by' => $request->user()?->id,
            'mode' => $mode,
            'status' => LifecycleRestoreRun::STATUS_QUEUED,
            'target_state' => $this->validatedTargetState($request),
            'batch_limit' => $this->validatedLimit($request),
            'filters' => $this->validatedFilters($request),
            'notes' => $request->filled('notes') ? (string) $request->input('notes') : null,
        ]);

        RunLifecycleRestoreJob::dispatch((int) $run->id);

        return response()->json(['data' => $this->presentRun($run->fresh())], 201);
    }

    /** Poll a single run — powers the progress indicator. */
    public function show(Request $request, LifecycleRestoreRun $run): JsonResponse
    {
        $denied = $this->assertPlatformAccess($request, (int) $run->platform_id);

        if ($denied) {
            return $denied;
        }

        return response()->json(['data' => $this->presentRun($run->load('requester:id,name'))]);
    }

    /** Put a batch back offline. */
    public function revert(Request $request, LifecycleRestoreRun $run): JsonResponse
    {
        $denied = $this->assertPlatformAccess($request, (int) $run->platform_id);

        if ($denied) {
            return $denied;
        }

        if (! $run->isRevertible()) {
            return response()->json([
                'message' => 'Only a completed live run with restored profiles can be reverted.',
            ], 422);
        }

        $result = $this->restorer->revert($run);

        return response()->json([
            'message' => sprintf('Reverted %d profile(s).', $result['reverted']),
            'data' => $this->presentRun($run->fresh()),
        ] + $result);
    }

    /** The restored cohort — for spot-checking and measuring the recovery. */
    public function cohort(Request $request): JsonResponse
    {
        $platform = $this->authorizedPlatform($request);

        if ($platform instanceof JsonResponse) {
            return $platform;
        }

        $validated = $request->validate([
            'run_id' => 'nullable|integer|min:1',
            'lifecycle_state' => 'nullable|in:expired,archived',
            'search' => 'nullable|string|max:120',
            'sort_by' => 'nullable|in:name,city,lifecycle_expired_at,lifecycle_restored_at,lifecycle_state',
            'sort_direction' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Client::query()
            ->where('platform_id', $platform->id)
            ->whereNotNull('lifecycle_restored_at')
            ->whereIn('lifecycle_state', [ClientLifecycleState::EXPIRED, ClientLifecycleState::ARCHIVED])
            ->with(['deals:id,client_id,status,expires_at', 'lifecycleRestoreRun:id,created_at']);

        if (! empty($validated['run_id'])) {
            $query->where('lifecycle_restore_run_id', (int) $validated['run_id']);
        }

        if (! empty($validated['lifecycle_state'])) {
            $query->where('lifecycle_state', (string) $validated['lifecycle_state']);
        }

        if (! empty($validated['search'])) {
            $search = trim((string) $validated['search']);
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('phone_normalized', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");

                if (ctype_digit($search)) {
                    $builder->orWhere('wp_post_id', (int) $search);
                }
            });
        }

        $sortBy = (string) ($validated['sort_by'] ?? 'lifecycle_restored_at');
        $sortDirection = (string) ($validated['sort_direction'] ?? 'desc');
        $clients = $query
            ->orderBy($sortBy, $sortDirection)
            ->orderBy('id', $sortDirection)
            ->paginate((int) ($validated['per_page'] ?? 25));

        $clients->getCollection()->transform(function (Client $client) {
            $hasEntitlement = $client->deals->contains(fn ($deal) => (string) $deal->status === 'active'
                && ($deal->expires_at === null || $deal->expires_at->isFuture()));
            $busy = $this->lifecycleLock->isBusy($client);
            $isRecovered = $client->lifecycle_restored_at !== null && $client->lifecycle_restore_run_id !== null;
            $isRestricted = in_array((string) $client->lifecycle_state, [ClientLifecycleState::EXPIRED, ClientLifecycleState::ARCHIVED], true);
            $isAgency = (string) ($client->client_type ?? 'escort') === 'agency';

            $reason = $busy
                ? 'lifecycle_busy'
                : ($hasEntitlement
                    ? 'paid_entitlement'
                    : (! $isRestricted ? 'wrong_state' : (! $isRecovered ? 'not_recovered' : null)));
            $deleteReason = $reason ?? ($isAgency ? 'agency_protected' : null);

            return [
                'id' => (int) $client->id,
                'name' => $client->name,
                'city' => $client->city,
                'wp_post_id' => (int) $client->wp_post_id,
                // The live URL is the whole point of the recovery — surfaced so a
                // restored profile can be spot-checked on the market site.
                'profile_url' => $client->wp_profile_permalink,
                'phone_normalized' => $client->phone_normalized,
                'lifecycle_state' => $client->lifecycle_state,
                'lifecycle_expired_at' => optional($client->lifecycle_expired_at)->toDateString(),
                'lifecycle_restored_at' => optional($client->lifecycle_restored_at)->toDateTimeString(),
                'lifecycle_restore_run_id' => $client->lifecycle_restore_run_id,
                'restore_run_created_at' => optional($client->lifecycleRestoreRun?->created_at)->toDateTimeString(),
                'last_online_at' => $client->last_online_at,
                'seo_score' => $client->seo_score !== null ? (int) $client->seo_score : null,
                'can_archive' => $reason === null && $client->lifecycle_state === ClientLifecycleState::EXPIRED,
                'archive_reason_code' => $reason ?? ($client->lifecycle_state !== ClientLifecycleState::EXPIRED ? 'wrong_state' : null),
                'can_revert' => $reason === null,
                'revert_reason_code' => $reason,
                'can_delete' => $deleteReason === null,
                'delete_reason_code' => $deleteReason,
            ];
        });

        return response()->json($clients);
    }

    public function revertClient(Request $request, Client $client): JsonResponse
    {
        if ($denied = $this->assertPlatformAccess($request, (int) $client->platform_id)) {
            return $denied;
        }

        $validated = $request->validate(['reason' => 'nullable|string|max:500']);

        try {
            $updated = $this->restorer->revertClient(
                $client,
                $request->user()?->id,
                $validated['reason'] ?? null,
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'reason_code' => 'wrong_state'], 422);
        } catch (\Illuminate\Http\Client\ConnectionException|\Illuminate\Http\Client\RequestException $exception) {
            return response()->json(['message' => 'WordPress did not confirm that the profile is private.'], 502);
        }

        return response()->json([
            'message' => 'Profile returned offline.',
            'client' => [
                'id' => (int) $updated->id,
                'profile_status' => $updated->profile_status,
                'lifecycle_state' => $updated->lifecycle_state,
                'lifecycle_restored_at' => $updated->lifecycle_restored_at,
                'lifecycle_restore_run_id' => $updated->lifecycle_restore_run_id,
            ],
        ]);
    }

    /** Read the per-market pacing policy. */
    public function pacing(Request $request): JsonResponse
    {
        $platform = $this->authorizedPlatform($request);

        if ($platform instanceof JsonResponse) {
            return $platform;
        }

        return response()->json(['data' => $this->pacingFor((int) $platform->id)]);
    }

    /** Write the per-market pacing policy. */
    public function updatePacing(Request $request): JsonResponse
    {
        $platform = $this->authorizedPlatform($request);

        if ($platform instanceof JsonResponse) {
            return $platform;
        }

        $config = [
            'mode' => LifecycleRestorePacing::normalize($request->input('mode')),
            'daily_quota' => max((int) $request->input('daily_quota', LifecycleRestorePacing::DEFAULT_DAILY_QUOTA), 1),
            'filters' => $this->validatedFilters($request),
            'target_state' => $this->validatedTargetState($request),
        ];

        $this->settings->set(
            LifecycleRestorePacing::settingsKey((int) $platform->id),
            $config,
            $request->user()?->id
        );

        return response()->json(['data' => $config]);
    }

    /** Static config for the UI: modes, labels, close reasons it excludes. */
    public function options(): JsonResponse
    {
        return response()->json([
            'history_modes' => LifecycleRestoreEligibility::HISTORY_MODES,
            'pacing_modes' => array_map(
                fn (string $mode) => ['value' => $mode, 'label' => LifecycleRestorePacing::label($mode)],
                LifecycleRestorePacing::ALL
            ),
            'bad_close_reasons' => LifecycleRestoreEligibility::BAD_CLOSE_REASONS,
            'archive_after_days' => \App\Support\LifecyclePolicy::archiveAfterDays(),
            'default_filters' => (new LifecycleRestoreEligibility)->toArray(),
        ]);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function presentRun(LifecycleRestoreRun $run): array
    {
        return [
            'id' => (int) $run->id,
            'platform_id' => (int) $run->platform_id,
            'mode' => $run->mode,
            'status' => $run->status,
            'target_state' => $run->target_state,
            'batch_limit' => (int) $run->batch_limit,
            'filters' => $run->filters,
            'candidate_count' => (int) $run->candidate_count,
            'restored_count' => (int) $run->restored_count,
            'skipped_count' => (int) $run->skipped_count,
            'failed_count' => (int) $run->failed_count,
            'started_at' => optional($run->started_at)->toDateTimeString(),
            'finished_at' => optional($run->finished_at)->toDateTimeString(),
            'notes' => $run->notes,
            'is_revertible' => $run->isRevertible(),
            'requested_by' => $run->relationLoaded('requester') ? optional($run->requester)->name : null,
            'created_at' => optional($run->created_at)->toDateTimeString(),
        ];
    }

    private function pacingFor(int $platformId): array
    {
        $stored = $this->settings->get(LifecycleRestorePacing::settingsKey($platformId));

        if (! is_array($stored)) {
            return LifecycleRestorePacing::defaults();
        }

        return array_merge(LifecycleRestorePacing::defaults(), $stored, [
            'mode' => LifecycleRestorePacing::normalize($stored['mode'] ?? null),
        ]);
    }

    /** Resolve and authorize the target market, or return the error response. */
    private function authorizedPlatform(Request $request): Platform|JsonResponse
    {
        $platformId = (int) $request->input('platform_id');

        if ($platformId <= 0) {
            return response()->json(['message' => 'platform_id is required.'], 422);
        }

        $denied = $this->assertPlatformAccess($request, $platformId);

        if ($denied) {
            return $denied;
        }

        $platform = Platform::query()->find($platformId);

        if (! $platform) {
            return response()->json(['message' => 'Market not found.'], 404);
        }

        return $platform;
    }

    /**
     * resolveAccessiblePlatformIds() returns null for admins — an is_array()
     * guard is required before treating it as a list.
     */
    private function assertPlatformAccess(Request $request, int $platformId): ?JsonResponse
    {
        $accessible = $this->marketAuth->resolveAccessiblePlatformIds($request->user());

        if (is_array($accessible) && ! in_array($platformId, $accessible, true)) {
            return response()->json(['message' => 'You do not have access to this market.'], 403);
        }

        return null;
    }

    private function validatedFilters(Request $request): array
    {
        $filters = $request->input('filters');

        return (new LifecycleRestoreEligibility(is_array($filters) ? $filters : []))->toArray();
    }

    private function validatedTargetState(Request $request): ?string
    {
        $state = $request->input('target_state');

        return in_array($state, [ClientLifecycleState::EXPIRED, ClientLifecycleState::ARCHIVED], true)
            ? $state
            : null;
    }

    private function validatedLimit(Request $request): int
    {
        $limit = (int) $request->input('batch_limit', 200);

        return max(min($limit, LifecycleRestorePacing::UNRESTRICTED_CEILING), 1);
    }
}
