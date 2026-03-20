<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\TimelineEvent;
use App\Services\AuditService;
use App\Services\MarketAuthorizationService;
use App\Services\SupportBoardProfileSyncService;
use App\Services\SupportBoardService;
use App\Support\CrmAuditAction;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class SupportBoardController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly AuditService $auditService
    ) {
    }

    public function status(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $client->loadMissing('platform');

        if ($request->boolean('refresh')) {
            SupportBoardService::clearResolveCache($client);
        }

        $service = new SupportBoardService($client->platform);
        if (!$service->isConfigured()) {
            return response()->json([
                'configured' => false,
                'matched' => false,
                'can_reply' => false,
                'sb_user' => null,
                'matched_by' => null,
                'tried' => [
                    'phone' => [],
                    'email' => strtolower(trim((string) ($client->email ?: ''))) ?: null,
                ],
            ]);
        }

        try {
            $resolved = $service->resolveClient($client);

            return response()->json([
                'configured' => true,
                'matched' => (bool) ($resolved['matched'] ?? false),
                'can_reply' => $service->canReply($request->user()),
                'sb_user' => $resolved['sb_user'] ?? null,
                'matched_by' => $resolved['matched_by'] ?? null,
                'tried' => $resolved['tried'] ?? [
                    'phone' => [],
                    'email' => null,
                ],
            ]);
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            return $this->supportBoardUnavailableResponse($exception);
        }
    }

    public function conversations(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $client->loadMissing('platform');

        if ($request->boolean('refresh')) {
            SupportBoardService::clearResolveCache($client);
        }

        $service = new SupportBoardService($client->platform);
        if (!$service->isConfigured()) {
            return response()->json([
                'message' => 'Support Board is not configured for this market.',
            ], 422);
        }

        try {
            $resolved = $service->resolveClient($client);
            if (!($resolved['matched'] ?? false) || empty($resolved['sb_user']['id'])) {
                return response()->json([]);
            }

            return response()->json($service->getConversations((int) $resolved['sb_user']['id']));
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            return $this->supportBoardUnavailableResponse($exception);
        }
    }

    public function profile(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $client->loadMissing('platform');

        if ($request->boolean('refresh')) {
            SupportBoardService::clearResolveCache($client);
        }

        $service = new SupportBoardService($client->platform);
        if (!$service->isConfigured()) {
            return response()->json([
                'configured' => false,
                'matched' => false,
                'matched_by' => null,
                'sb_user' => null,
                'details' => [],
                'detail_map' => (object) [],
                'primary_details' => [],
                'secondary_details' => [],
                'suggestions' => [
                    'market' => null,
                    'city' => null,
                    'location' => null,
                ],
            ]);
        }

        try {
            $profileService = new SupportBoardProfileSyncService($client, $service);

            return response()->json($profileService->buildProfilePayload());
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'Client is not linked to Support Board.') {
                return response()->json([
                    'configured' => true,
                    'matched' => false,
                    'matched_by' => $client->sb_matched_by,
                    'sb_user' => null,
                    'details' => [],
                    'detail_map' => (object) [],
                    'primary_details' => [],
                    'secondary_details' => [],
                    'suggestions' => [
                        'market' => null,
                        'city' => null,
                        'location' => null,
                    ],
                ]);
            }

            return $this->supportBoardUnavailableResponse($exception);
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            return $this->supportBoardUnavailableResponse($exception);
        }
    }

    public function previewProfileSync(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'direction' => 'required|in:support_board_to_crm,crm_to_support_board',
            'mode' => 'required|in:fill_blanks,overwrite',
            'fields' => 'required|array|min:1',
            'fields.*' => 'required|in:name,email,phone,city',
        ]);

        $client->loadMissing('platform');
        $service = new SupportBoardService($client->platform);
        if (!$service->isConfigured()) {
            return response()->json([
                'message' => 'Support Board is not configured for this market.',
            ], 422);
        }

        try {
            $profileService = new SupportBoardProfileSyncService($client, $service);

            return response()->json(
                $profileService->preview(
                    (string) $validated['direction'],
                    (string) $validated['mode'],
                    (array) $validated['fields'],
                )
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            return $this->supportBoardUnavailableResponse($exception);
        }
    }

    public function applyProfileSync(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'direction' => 'required|in:support_board_to_crm,crm_to_support_board',
            'mode' => 'required|in:fill_blanks,overwrite',
            'fields' => 'required|array|min:1',
            'fields.*' => 'required|in:name,email,phone,city',
            'reason' => 'required|string|max:500',
        ]);

        $client->loadMissing('platform');
        $service = new SupportBoardService($client->platform);
        if (!$service->isConfigured()) {
            return response()->json([
                'message' => 'Support Board is not configured for this market.',
            ], 422);
        }

        try {
            $profileService = new SupportBoardProfileSyncService($client, $service);
            $result = $profileService->apply(
                (string) $validated['direction'],
                (string) $validated['mode'],
                (array) $validated['fields'],
            );

            if (($result['applied_count'] ?? 0) > 0) {
                TimelineEvent::create([
                    'platform_id' => $client->platform_id,
                    'entity_type' => 'client',
                    'entity_id' => $client->id,
                    'event_type' => 'support_board_profile_sync',
                    'actor_id' => $request->user()->id,
                    'content' => [
                        'direction' => $validated['direction'],
                        'mode' => $validated['mode'],
                        'changed_fields' => $result['changed_fields'],
                        'applied_count' => $result['applied_count'],
                    ],
                    'created_at' => now(),
                ]);

                $this->auditService->fromRequest(
                    $request,
                    (int) $client->platform_id,
                    CrmAuditAction::CLIENT_SUPPORT_BOARD_PROFILE_SYNC,
                    'client',
                    (int) $client->id,
                    $result['before'] ?? null,
                    array_merge(
                        $result['after'] ?? [],
                        [
                            'direction' => $validated['direction'],
                            'mode' => $validated['mode'],
                            'changed_fields' => $result['changed_fields'],
                        ],
                    ),
                    (string) $validated['reason']
                );
            }

            return response()->json([
                'message' => ($result['applied_count'] ?? 0) > 0
                    ? 'Support Board profile sync applied.'
                    : 'No profile changes were needed.',
                'sync' => $result,
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            return $this->supportBoardUnavailableResponse($exception);
        }
    }

    public function conversation(Request $request, Client $client, int $conversationId)
    {
        $this->authorizeClientAccess($request, $client);

        $client->loadMissing('platform');
        $service = new SupportBoardService($client->platform);
        if (!$service->isConfigured()) {
            return response()->json([
                'message' => 'Support Board is not configured for this market.',
            ], 422);
        }

        try {
            $resolved = $service->resolveClient($client);
            $sbUserId = (int) ($resolved['sb_user']['id'] ?? $client->sb_user_id ?? 0);
            if ($sbUserId <= 0) {
                return response()->json([
                    'message' => 'Client is not linked to Support Board.',
                ], 404);
            }

            $conversation = $service->getConversation($conversationId);
            if (empty($conversation['id'])) {
                return response()->json([
                    'message' => 'Conversation not found.',
                ], 404);
            }

            $this->ensureConversationOwnership($conversation, $sbUserId);

            return response()->json($conversation);
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            return $this->supportBoardUnavailableResponse($exception);
        }
    }

    public function reply(Request $request, Client $client, int $conversationId)
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'message' => 'required|string|max:5000',
        ]);

        $client->loadMissing('platform');
        $service = new SupportBoardService($client->platform);
        if (!$service->isConfigured()) {
            return response()->json([
                'message' => 'Support Board is not configured for this market.',
            ], 422);
        }

        try {
            $resolved = $service->resolveClient($client);
            $sbUserId = (int) ($resolved['sb_user']['id'] ?? $client->sb_user_id ?? 0);
            if ($sbUserId <= 0) {
                return response()->json([
                    'message' => 'Client is not linked to Support Board.',
                ], 404);
            }

            $senderSbUserId = $request->user()->sb_agent_id ?: $client->platform->support_board_sender_id;
            if (empty($senderSbUserId)) {
                return response()->json([
                    'message' => 'No sender configured.',
                ], 422);
            }

            $conversation = $service->getConversation($conversationId);
            if (empty($conversation['id'])) {
                return response()->json([
                    'message' => 'Conversation not found.',
                ], 404);
            }

            $this->ensureConversationOwnership($conversation, $sbUserId);

            $sentMessage = $service->sendMessage(
                $conversationId,
                (string) $validated['message'],
                (int) $senderSbUserId
            );

            $note = ClientNote::create([
                'client_id' => $client->id,
                'author_id' => $request->user()->id,
                'note_type' => 'support_chat',
                'content' => (string) $validated['message'],
                'created_at' => now(),
            ]);

            TimelineEvent::create([
                'platform_id' => $client->platform_id,
                'entity_type' => 'client',
                'entity_id' => $client->id,
                'event_type' => 'support_chat_reply',
                'actor_id' => $request->user()->id,
                'content' => [
                    'conversation_id' => $conversationId,
                    'message_id' => $sentMessage['id'] ?? null,
                    'note_id' => $note->id,
                    'sender_sb_user_id' => (int) $senderSbUserId,
                ],
                'created_at' => now(),
            ]);

            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                'support_chat_reply',
                'client',
                (int) $client->id,
                null,
                [
                    'conversation_id' => $conversationId,
                    'message_id' => $sentMessage['id'] ?? null,
                    'sender_sb_user_id' => (int) $senderSbUserId,
                ],
                'Support Board chat reply sent from CRM'
            );

            $note->load('author');

            return response()->json([
                'message' => $sentMessage,
                'note' => $note,
            ], 201);
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            return $this->supportBoardUnavailableResponse($exception);
        }
    }

    private function authorizeClientAccess(Request $request, Client $client): void
    {
        if (!$this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $client->platform_id)) {
            abort(403, 'You do not have access to this client market.');
        }
    }

    private function ensureConversationOwnership(array $conversation, int $sbUserId): void
    {
        if ((int) ($conversation['user_id'] ?? 0) !== $sbUserId) {
            abort(403, 'Conversation does not belong to this client.');
        }
    }

    private function supportBoardUnavailableResponse(Throwable $exception)
    {
        report($exception);

        return response()->json([
            'message' => "Couldn't reach Support Board.",
            'details' => $exception->getMessage(),
        ], 503);
    }
}
