<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CRM\Concerns\RespondsToWordPressStories;
use App\Models\Client;
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
 * Per-client story controls. WordPress owns stories (escortwp-child theme);
 * every read is live and every action goes through the exotic-crm-sync
 * plugin, which checks story ownership before calling the theme.
 */
class ClientStoryController extends Controller
{
    use RespondsToWordPressStories;

    /**
     * Roles that may moderate stories or pause posting. Everyone who can open
     * the client profile can read the Stories tab.
     */
    public const MANAGER_ROLES = ['admin', 'sub_admin', 'sales'];

    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly AuditService $auditService,
    ) {}

    public function index(Request $request, Client $client): JsonResponse
    {
        $this->authorizeClientAccess($request, $client);

        if ($response = $this->unlinkedResponse($client)) {
            return $response;
        }

        $canManage = $this->marketAuthorizationService->hasRole($request->user(), self::MANAGER_ROLES);

        try {
            $payload = WpSyncService::forPlatform((int) $client->platform_id)
                ->getClientStories((int) $client->wp_post_id);
        } catch (RequestException $exception) {
            // Markets still on a plugin build without the stories routes.
            if ($this->isMissingRoute($exception)) {
                return response()->json([
                    'enabled' => false,
                    'state' => 'stories_unavailable',
                    'unavailable_reason' => 'plugin_outdated',
                    'stories' => [],
                    'can_manage' => $canManage,
                ]);
            }

            return $this->wordpressFailure($exception, 'Failed to load stories from WordPress.');
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Failed to load stories from WordPress.',
                'error' => $exception->getMessage(),
            ], 502);
        }

        return response()->json($payload + ['can_manage' => $canManage]);
    }

    public function store(Request $request, Client $client): JsonResponse
    {
        $this->authorizeManager($request, $client);

        if ($response = $this->unlinkedResponse($client)) {
            return $response;
        }

        $validated = $request->validate([
            'attachment_id' => ['nullable', 'integer', 'min:1', 'required_without:file'],
            // Matches the theme's story limits: photos 15 MB, videos 100 MB.
            'file' => ['nullable', 'file', 'required_without:attachment_id', 'mimes:jpg,jpeg,png,webp,mp4,webm,mov', 'max:102400'],
            'caption' => ['nullable', 'string', 'max:220'],
            'start' => ['nullable', 'numeric', 'min:0', 'max:1800'],
            'parts' => ['nullable', 'integer', 'min:1', 'max:8'],
        ]);

        $file = $request->file('file');
        if ($file && str_starts_with((string) $file->getMimeType(), 'image/') && $file->getSize() > 15 * 1024 * 1024) {
            return response()->json([
                'message' => 'Photos must be 15 MB or smaller.',
                'errors' => ['file' => ['Photos must be 15 MB or smaller.']],
            ], 422);
        }

        try {
            $result = WpSyncService::forPlatform((int) $client->platform_id)->createClientStory(
                (int) $client->wp_post_id,
                [
                    'attachment_id' => $file ? null : ($validated['attachment_id'] ?? null),
                    'caption' => $validated['caption'] ?? null,
                    'start' => $validated['start'] ?? null,
                    'parts' => $validated['parts'] ?? null,
                    'actor' => (string) ($request->user()?->name ?? ''),
                ],
                $file,
            );
        } catch (RequestException $exception) {
            if ($this->isMissingRoute($exception)) {
                return response()->json([
                    'message' => 'This market runs an older CRM sync plugin that cannot post stories. Upload exotic-crm-sync 1.3.11 or later.',
                    'code' => 'plugin_outdated',
                ], 409);
            }

            return $this->wordpressFailure($exception, 'WordPress could not post the story.');
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'WordPress could not post the story.', 'error' => $exception->getMessage()], 502);
        }

        $storyIds = array_map('intval', (array) ($result['story_ids'] ?? []));
        $this->record($request, $client, 'story_posted', CrmAuditAction::CLIENT_STORY_CREATE, [
            'story_ids' => $storyIds,
            'source' => $result['source'] ?? ($file ? 'upload' : 'profile_media'),
            'attachment_id' => $file ? null : ($validated['attachment_id'] ?? null),
            'parts' => count($storyIds),
            'caption' => $validated['caption'] ?? null,
        ], 'Story posted on the advertiser\'s behalf from CRM');

        return response()->json($result, 201);
    }

    public function moderate(Request $request, Client $client, int $storyId): JsonResponse
    {
        $this->authorizeManager($request, $client);

        if ($response = $this->unlinkedResponse($client)) {
            return $response;
        }

        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'hide', 'delete'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $reason = $this->cleanReason($validated['reason'] ?? null);

        try {
            $result = WpSyncService::forPlatform((int) $client->platform_id)
                ->moderateClientStory((int) $client->wp_post_id, $storyId, $validated['action']);
        } catch (RequestException $exception) {
            return $this->wordpressFailure($exception, 'WordPress could not update the story.');
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'WordPress could not update the story.', 'error' => $exception->getMessage()], 502);
        }

        $this->record($request, $client, 'story_moderated', CrmAuditAction::CLIENT_STORY_MODERATE, [
            'story_id' => $storyId,
            'action' => $validated['action'],
            'before' => $result['before'] ?? null,
            'reason' => $reason,
        ], $reason ?? "Story {$validated['action']} from CRM");

        return response()->json($result);
    }

    public function expire(Request $request, Client $client, int $storyId): JsonResponse
    {
        $this->authorizeManager($request, $client);

        if ($response = $this->unlinkedResponse($client)) {
            return $response;
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $reason = $this->cleanReason($validated['reason'] ?? null);

        try {
            $result = WpSyncService::forPlatform((int) $client->platform_id)
                ->expireClientStory((int) $client->wp_post_id, $storyId);
        } catch (RequestException $exception) {
            return $this->wordpressFailure($exception, 'WordPress could not end the story.');
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'WordPress could not end the story.', 'error' => $exception->getMessage()], 502);
        }

        $this->record($request, $client, 'story_expired', CrmAuditAction::CLIENT_STORY_EXPIRE, [
            'story_id' => $storyId,
            'reason' => $reason,
        ], $reason ?? 'Story ended early from CRM');

        return response()->json($result);
    }

    public function posting(Request $request, Client $client): JsonResponse
    {
        $this->authorizeManager($request, $client);

        if ($response = $this->unlinkedResponse($client)) {
            return $response;
        }

        $validated = $request->validate([
            'blocked' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $blocked = (bool) $validated['blocked'];
        $reason = $this->cleanReason($validated['reason'] ?? null);

        if ($blocked && $reason === null) {
            return response()->json([
                'message' => 'A reason is required to pause story posting.',
                'errors' => ['reason' => ['A reason is required to pause story posting.']],
            ], 422);
        }

        try {
            $result = WpSyncService::forPlatform((int) $client->platform_id)->setClientStoryPosting(
                (int) $client->wp_post_id,
                $blocked,
                $reason,
                $blocked ? (string) ($request->user()?->name ?? '') : null,
            );
        } catch (RequestException $exception) {
            return $this->wordpressFailure($exception, 'WordPress could not update story posting.');
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'WordPress could not update story posting.', 'error' => $exception->getMessage()], 502);
        }

        $this->record(
            $request,
            $client,
            $blocked ? 'story_posting_blocked' : 'story_posting_unblocked',
            CrmAuditAction::CLIENT_STORY_POSTING_UPDATE,
            [
                'blocked' => $blocked,
                'was_blocked' => $result['was_blocked'] ?? null,
                'block_enforced' => $result['block_enforced'] ?? null,
                'reason' => $reason,
            ],
            $reason ?? 'Story posting resumed from CRM',
        );

        return response()->json($result);
    }

    private function record(Request $request, Client $client, string $eventType, string $auditAction, array $content, string $reason): void
    {
        TimelineEvent::create([
            'platform_id' => $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => $eventType,
            'actor_id' => $request->user()?->id,
            'content' => $content,
            'created_at' => now(),
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $client->platform_id,
            $auditAction,
            'client',
            (int) $client->id,
            null,
            $content,
            $reason,
        );
    }

    private function authorizeClientAccess(Request $request, Client $client): void
    {
        if (! $this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $client->platform_id)) {
            abort(403, 'You do not have access to this client market.');
        }
    }

    private function authorizeManager(Request $request, Client $client): void
    {
        $this->authorizeClientAccess($request, $client);
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            self::MANAGER_ROLES,
            'Only admin, sub-admin or sales users can manage stories.'
        );
    }

    private function unlinkedResponse(Client $client): ?JsonResponse
    {
        if ((int) $client->wp_post_id > 0) {
            return null;
        }

        return response()->json(['message' => 'This client is not linked to a WordPress profile.'], 422);
    }

    private function cleanReason(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : $reason;
    }
}
