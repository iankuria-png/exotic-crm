<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CRM\Concerns\RespondsToWordPressStories;
use App\Models\Client;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Services\AuditService;
use App\Services\MarketAuthorizationService;
use App\Services\WpSyncService;
use App\Support\CrmAuditAction;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The CRM Stories page: the market-wide controls of wp-admin → Stories
 * (review, live list, weekly reward, brand stories and settings), proxied
 * live to the market's exotic-crm-sync plugin (1.3.12+).
 *
 * Reviewing matches the per-client Stories tab (admin, sub_admin, sales).
 * Brand stories need a manager; settings and the weekly reward are
 * admin-only, as they are administrator-only in wp-admin.
 */
class StoriesController extends Controller
{
    use RespondsToWordPressStories;

    public const REVIEW_ROLES = ['admin', 'sub_admin', 'sales'];

    public const BRAND_ROLES = ['admin', 'sub_admin'];

    public const ADMIN_ROLES = ['admin'];

    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly AuditService $auditService,
    ) {}

    /**
     * Markets this user can open on the Stories page.
     */
    public function markets(Request $request): JsonResponse
    {
        $allowed = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        $query = Platform::query()
            ->whereNotNull('wp_api_url')
            ->where('wp_api_url', '!=', '')
            ->orderBy('name');

        // Admins resolve to null (every market); guard before whereIn.
        if (is_array($allowed)) {
            if ($allowed === []) {
                return response()->json(['data' => []]);
            }
            $query->whereIn('id', $allowed);
        }

        return response()->json([
            'data' => $query->get(['id', 'name', 'country', 'domain', 'is_active'])->map(fn (Platform $platform) => [
                'id' => (int) $platform->id,
                'name' => (string) $platform->name,
                'country' => $platform->country,
                'domain' => $platform->domain,
                'is_active' => (bool) $platform->is_active,
            ])->values(),
        ]);
    }

    public function overview(Request $request, Platform $platform): JsonResponse
    {
        $this->authorizeMarket($request, $platform);

        try {
            $payload = $this->wp($platform)->getStoriesOverview();
        } catch (RequestException $exception) {
            if ($this->isMissingRoute($exception)) {
                return response()->json([
                    'available' => false,
                    'unavailable_reason' => 'plugin_outdated',
                    'enabled' => false,
                    'abilities' => $this->abilities($request),
                ]);
            }

            return $this->wordpressFailure($exception, 'Failed to load stories from WordPress.');
        } catch (\Throwable $exception) {
            return $this->unreachable('Failed to load stories from WordPress.', $exception);
        }

        return response()->json($payload + ['abilities' => $this->abilities($request)]);
    }

    public function index(Request $request, Platform $platform): JsonResponse
    {
        $this->authorizeMarket($request, $platform);

        $validated = $request->validate([
            'state' => ['nullable', Rule::in(['all', 'unreviewed', 'approved', 'hidden'])],
            'search' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return $this->proxy(function () use ($platform, $validated) {
            $payload = $this->wp($platform)->getStoriesList($validated);
            $clients = $this->clientIdsByPostId($platform, array_map(
                static fn ($story) => (int) ($story['profile']['post_id'] ?? 0),
                (array) ($payload['stories'] ?? [])
            ));
            $payload['stories'] = array_map(static function ($story) use ($clients) {
                $story['client_id'] = $clients[(int) ($story['profile']['post_id'] ?? 0)] ?? null;

                return $story;
            }, (array) ($payload['stories'] ?? []));

            return $payload;
        }, 'Failed to load stories from WordPress.');
    }

    public function moderate(Request $request, Platform $platform): JsonResponse
    {
        $this->authorizeMarket($request, $platform, self::REVIEW_ROLES);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'hide', 'delete', 'expire'])],
            'story_ids' => ['required', 'array', 'min:1', 'max:100'],
            'story_ids.*' => ['integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $reason = trim((string) ($validated['reason'] ?? '')) ?: null;

        try {
            $result = $this->wp($platform)->moderateStories($validated['action'], $validated['story_ids']);
        } catch (RequestException $exception) {
            return $this->wordpressFailure($exception, 'WordPress could not update the stories.');
        } catch (\Throwable $exception) {
            return $this->unreachable('WordPress could not update the stories.', $exception);
        }

        $done = array_values(array_filter((array) ($result['results'] ?? []), static fn ($row) => ! empty($row['ok'])));
        $clients = $this->clientIdsByPostId($platform, array_map(static fn ($row) => (int) ($row['profile_post_id'] ?? 0), $done));

        // Each affected advertiser's CRM timeline shows what happened to their story.
        foreach ($done as $row) {
            $clientId = $clients[(int) ($row['profile_post_id'] ?? 0)] ?? null;
            if (! $clientId) {
                continue;
            }
            TimelineEvent::create([
                'platform_id' => $platform->id,
                'entity_type' => 'client',
                'entity_id' => $clientId,
                'event_type' => $validated['action'] === 'expire' ? 'story_expired' : 'story_moderated',
                'actor_id' => $request->user()?->id,
                'content' => [
                    'story_id' => (int) $row['story_id'],
                    'action' => $validated['action'],
                    'before' => $row['before'] ?? null,
                    'reason' => $reason,
                    'source' => 'stories_page',
                ],
                'created_at' => now(),
            ]);
        }

        $this->audit($request, $platform, CrmAuditAction::STORIES_MODERATE, [
            'action' => $validated['action'],
            'story_ids' => array_map(static fn ($row) => (int) $row['story_id'], $done),
            'requested' => count($validated['story_ids']),
        ], $reason ?? "Stories {$validated['action']} from the Stories page");

        return response()->json($result);
    }

    public function hottest(Request $request, Platform $platform): JsonResponse
    {
        $this->authorizeMarket($request, $platform);

        return $this->proxy(function () use ($platform) {
            $payload = $this->wp($platform)->getStoriesHottest();
            $postIds = array_merge(
                array_map(static fn ($row) => (int) ($row['profile_post_id'] ?? 0), (array) ($payload['board'] ?? [])),
                array_map(static fn ($row) => (int) ($row['profile_post_id'] ?? 0), (array) ($payload['history'] ?? [])),
                [(int) ($payload['previous_week']['winner']['profile_post_id'] ?? 0)],
            );
            $clients = $this->clientIdsByPostId($platform, $postIds);
            $attach = static function (array $rows) use ($clients) {
                return array_map(static function ($row) use ($clients) {
                    $row['client_id'] = $clients[(int) ($row['profile_post_id'] ?? 0)] ?? null;

                    return $row;
                }, $rows);
            };
            $payload['board'] = $attach((array) ($payload['board'] ?? []));
            $payload['history'] = $attach((array) ($payload['history'] ?? []));

            return $payload;
        }, 'Failed to load the weekly leaderboard.');
    }

    public function award(Request $request, Platform $platform): JsonResponse
    {
        $this->authorizeMarket($request, $platform, self::ADMIN_ROLES, 'Only admins can grant the weekly reward.');
        $validated = $request->validate(['week' => ['required', 'string', 'max:10']]);

        return $this->act($request, $platform, fn () => $this->wp($platform)->awardStoriesWeek($validated['week']), CrmAuditAction::STORIES_REWARD_AWARD, static fn ($result) => [
            'week' => $result['week'] ?? $validated['week'],
            'profile_post_id' => $result['profile_post_id'] ?? null,
            'name' => $result['name'] ?? null,
            'likes' => $result['likes'] ?? null,
        ], 'Weekly story reward granted from the Stories page', 'WordPress could not grant the reward.');
    }

    public function revoke(Request $request, Platform $platform): JsonResponse
    {
        $this->authorizeMarket($request, $platform, self::ADMIN_ROLES, 'Only admins can revoke the weekly reward.');
        $validated = $request->validate([
            'week' => ['required', 'string', 'max:10'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return $this->act($request, $platform, fn () => $this->wp($platform)->revokeStoriesWeek($validated['week']), CrmAuditAction::STORIES_REWARD_REVOKE, static fn ($result) => [
            'week' => $validated['week'],
            'profile_post_id' => $result['profile_post_id'] ?? null,
        ], trim($validated['reason']), 'WordPress could not revoke the reward.');
    }

    public function brand(Request $request, Platform $platform): JsonResponse
    {
        $this->authorizeMarket($request, $platform);

        return $this->proxy(fn () => $this->wp($platform)->getBrandStories(), 'Failed to load brand stories.');
    }

    public function storeBrand(Request $request, Platform $platform): JsonResponse
    {
        $this->authorizeMarket($request, $platform, self::BRAND_ROLES, 'Only admin or sub-admin users can post brand stories.');

        $validated = $request->validate([
            'kind' => ['required', Rule::in(['announcement', 'advert', 'poll'])],
            'headline' => ['required', 'string', 'max:90'],
            'body' => ['nullable', 'string', 'max:240'],
            'theme' => ['nullable', Rule::in(['crimson', 'gold', 'midnight', 'rose'])],
            'cta_label' => ['nullable', 'string', 'max:28', 'required_with:cta_url', 'required_if:kind,advert'],
            'cta_url' => ['nullable', 'url:http,https', 'max:500', 'required_with:cta_label', 'required_if:kind,advert'],
            'days' => ['nullable', 'integer', 'min:1', 'max:14'],
            'seconds' => ['nullable', 'integer', 'min:5', 'max:20'],
            'options' => ['nullable', 'array', 'max:4', 'required_if:kind,poll'],
            'options.*' => ['nullable', 'string', 'max:40'],
            'file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,mp4,webm,mov', 'max:102400'],
        ]);

        if ($validated['kind'] === 'poll' && count(array_filter($validated['options'] ?? [], static fn ($o) => trim((string) $o) !== '')) < 2) {
            return response()->json([
                'message' => 'Polls need at least two options.',
                'errors' => ['options' => ['Polls need at least two options.']],
            ], 422);
        }

        $fields = collect($validated)->except('file')->all();
        $fields['options'] = array_values(array_filter($validated['options'] ?? [], static fn ($o) => trim((string) $o) !== ''));
        $fields['actor'] = (string) ($request->user()?->name ?? '');

        $response = $this->act(
            $request,
            $platform,
            fn () => $this->wp($platform)->createBrandStory($fields, $request->file('file')),
            CrmAuditAction::STORIES_BRAND_CREATE,
            static fn ($result) => [
                'story_id' => $result['story_id'] ?? null,
                'kind' => $validated['kind'],
                'headline' => $validated['headline'],
                'days' => $validated['days'] ?? null,
            ],
            'Brand story posted from the Stories page',
            'WordPress could not post the brand story.',
        );

        return $response->getStatusCode() === 200 ? $response->setStatusCode(201) : $response;
    }

    public function endBrand(Request $request, Platform $platform, int $storyId): JsonResponse
    {
        $this->authorizeMarket($request, $platform, self::BRAND_ROLES, 'Only admin or sub-admin users can manage brand stories.');

        return $this->act($request, $platform, fn () => $this->wp($platform)->endBrandStory($storyId), CrmAuditAction::STORIES_BRAND_END, static fn () => [
            'story_id' => $storyId,
        ], 'Brand story ended from the Stories page', 'WordPress could not end the brand story.');
    }

    public function destroyBrand(Request $request, Platform $platform, int $storyId): JsonResponse
    {
        $this->authorizeMarket($request, $platform, self::BRAND_ROLES, 'Only admin or sub-admin users can manage brand stories.');

        return $this->act($request, $platform, fn () => $this->wp($platform)->deleteBrandStory($storyId), CrmAuditAction::STORIES_BRAND_DELETE, static fn () => [
            'story_id' => $storyId,
        ], 'Brand story deleted from the Stories page', 'WordPress could not delete the brand story.');
    }

    public function settings(Request $request, Platform $platform): JsonResponse
    {
        $this->authorizeMarket($request, $platform);

        return $this->proxy(fn () => $this->wp($platform)->getStorySettings(), 'Failed to load story settings.');
    }

    public function saveSettings(Request $request, Platform $platform): JsonResponse
    {
        $this->authorizeMarket($request, $platform, self::ADMIN_ROLES, 'Only admins can change story settings.');

        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'moderation' => ['nullable', Rule::in(['after', 'before'])],
            'max_active' => ['nullable', 'integer', 'min:1', 'max:50'],
            'auto_award' => ['nullable', 'boolean'],
            'min_likes' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'reward_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'house_label' => ['nullable', 'string', 'max:24'],
            'house_avatar' => ['nullable', 'integer', 'min:0'],
            'clip_seconds' => ['nullable', Rule::in([15, 30, 60])],
            'max_split' => ['nullable', 'integer', 'min:1', 'max:8'],
            'photo_seconds' => ['nullable', Rule::in([3, 5, 7, 10, 15])],
            'lifetime_hours' => ['nullable', Rule::in([12, 24, 48])],
            'house_avatar_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $fields = collect($validated)->except('house_avatar_file')->all();
        // An empty label is meaningful (fall back to "Exotic"), so send it.
        if ($request->has('house_label')) {
            $fields['house_label'] = (string) ($validated['house_label'] ?? '');
        }

        try {
            $result = $this->wp($platform)->saveStorySettings($fields, $request->file('house_avatar_file'));
        } catch (RequestException $exception) {
            return $this->wordpressFailure($exception, 'WordPress could not save the story settings.');
        } catch (\Throwable $exception) {
            return $this->unreachable('WordPress could not save the story settings.', $exception);
        }

        $before = (array) ($result['before'] ?? []);
        $after = (array) ($result['settings'] ?? []);
        $changed = array_keys(array_filter($after, static fn ($value, $key) => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));

        $this->auditService->fromRequest(
            $request,
            (int) $platform->id,
            CrmAuditAction::STORIES_SETTINGS_UPDATE,
            'platform',
            (int) $platform->id,
            array_intersect_key($before, array_flip($changed)),
            array_intersect_key($after, array_flip($changed)),
            'Story settings changed from the Stories page',
        );

        return response()->json($result);
    }

    /* ── Helpers ─────────────────────────────────────────────────────── */

    private function wp(Platform $platform): WpSyncService
    {
        return WpSyncService::forPlatform((int) $platform->id);
    }

    private function authorizeMarket(Request $request, Platform $platform, array $roles = self::REVIEW_ROLES, string $message = 'You do not have permission to manage stories.'): void
    {
        if (! $this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $platform->id)) {
            abort(403, 'You do not have access to this market.');
        }
        $this->marketAuthorizationService->ensureRole($request->user(), $roles, $message);
    }

    private function abilities(Request $request): array
    {
        $user = $request->user();

        return [
            'moderate' => $this->marketAuthorizationService->hasRole($user, self::REVIEW_ROLES),
            'post_for_client' => $this->marketAuthorizationService->hasRole($user, ClientStoryController::MANAGER_ROLES),
            'brand' => $this->marketAuthorizationService->hasRole($user, self::BRAND_ROLES),
            'reward' => $this->marketAuthorizationService->hasRole($user, self::ADMIN_ROLES),
            'settings' => $this->marketAuthorizationService->hasRole($user, self::ADMIN_ROLES),
        ];
    }

    /**
     * @param  array<int, int>  $postIds
     * @return array<int, int> wp_post_id => client id
     */
    private function clientIdsByPostId(Platform $platform, array $postIds): array
    {
        $postIds = array_values(array_unique(array_filter($postIds)));
        if ($postIds === []) {
            return [];
        }

        return Client::query()
            ->where('platform_id', $platform->id)
            ->whereIn('wp_post_id', $postIds)
            ->pluck('id', 'wp_post_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function proxy(callable $read, string $message): JsonResponse
    {
        try {
            return response()->json($read());
        } catch (RequestException $exception) {
            return $this->wordpressFailure($exception, $message);
        } catch (\Throwable $exception) {
            return $this->unreachable($message, $exception);
        }
    }

    private function act(Request $request, Platform $platform, callable $write, string $auditAction, callable $auditState, string $reason, string $message): JsonResponse
    {
        try {
            $result = $write();
        } catch (RequestException $exception) {
            return $this->wordpressFailure($exception, $message);
        } catch (\Throwable $exception) {
            return $this->unreachable($message, $exception);
        }

        $this->audit($request, $platform, $auditAction, $auditState($result), $reason);

        return response()->json($result);
    }

    private function audit(Request $request, Platform $platform, string $action, array $state, string $reason): void
    {
        $this->auditService->fromRequest($request, (int) $platform->id, $action, 'platform', (int) $platform->id, null, $state, $reason);
    }

    private function unreachable(string $message, \Throwable $exception): JsonResponse
    {
        return response()->json(['message' => $message, 'error' => $exception->getMessage()], 502);
    }
}
