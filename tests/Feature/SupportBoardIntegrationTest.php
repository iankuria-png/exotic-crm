<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\SupportBoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupportBoardIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_detail_masks_support_board_token_and_patch_preserves_or_updates_it(): void
    {
        $platform = $this->createPlatform([
            'support_board_api_url' => 'https://cloud.board.support/script/include/api.php',
            'support_board_token' => 'current-token',
            'support_board_sender_id' => 101,
        ]);
        $admin = $this->createUser('admin');

        Sanctum::actingAs($admin);

        $detailResponse = $this->getJson('/api/crm/settings/integrations');
        $detailResponse->assertOk()
            ->assertJsonPath('platforms.0.support_board_api_url', 'https://cloud.board.support/script/include/api.php')
            ->assertJsonPath('platforms.0.support_board_token_configured', true)
            ->assertJsonPath('platforms.0.support_board_sender_id', 101);

        $platformPayload = $detailResponse->json('platforms.0');
        $this->assertIsArray($platformPayload);
        $this->assertArrayNotHasKey('support_board_token', $platformPayload);

        $preserveResponse = $this->patchJson("/api/crm/settings/integrations/platforms/{$platform->id}", [
            'name' => 'Updated Market',
            'support_board_api_url' => 'https://cloud.board.support/script/include/api.php',
            'support_board_token' => '',
            'support_board_sender_id' => 202,
            'reason' => 'Preserve existing SB token while updating sender',
        ]);

        $preserveResponse->assertOk()
            ->assertJsonPath('platform.support_board_token_configured', true)
            ->assertJsonPath('platform.support_board_sender_id', 202)
            ->assertJsonPath('platform.support_board_api_url', 'https://cloud.board.support/script/include/api.php');

        $platform->refresh();
        $this->assertSame('current-token', $platform->support_board_token);
        $this->assertSame(202, (int) $platform->support_board_sender_id);

        $updateResponse = $this->patchJson("/api/crm/settings/integrations/platforms/{$platform->id}", [
            'support_board_token' => 'next-token',
            'support_board_api_url' => 'https://cloud.board.support/script/include/api.php?tenant=crm',
            'support_board_sender_id' => 303,
            'reason' => 'Rotate the SB token',
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('platform.support_board_token_configured', true)
            ->assertJsonPath('platform.support_board_api_url', 'https://cloud.board.support/script/include/api.php?tenant=crm')
            ->assertJsonPath('platform.support_board_sender_id', 303);

        $platform->refresh();
        $this->assertSame('next-token', $platform->support_board_token);
        $this->assertSame('https://cloud.board.support/script/include/api.php?tenant=crm', $platform->support_board_api_url);
        $this->assertSame(303, (int) $platform->support_board_sender_id);
    }

    public function test_client_serialization_hides_sensitive_platform_fields(): void
    {
        $platform = $this->createPlatform([
            'support_board_token' => 'hidden-token',
            'wp_api_password' => 'hidden-wp-password',
            'db_pass' => 'hidden-db-password',
        ]);
        $client = $this->createClient($platform);
        $sales = $this->createUser('sales', [$platform->id]);

        Sanctum::actingAs($sales);

        $response = $this->getJson("/api/crm/clients/{$client->id}");

        $response->assertOk();

        $platformPayload = $response->json('platform');
        $this->assertIsArray($platformPayload);
        $this->assertArrayNotHasKey('support_board_token', $platformPayload);
        $this->assertArrayNotHasKey('wp_api_password', $platformPayload);
        $this->assertArrayNotHasKey('db_pass', $platformPayload);
    }

    public function test_marketing_role_is_blocked_from_support_board_routes(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createClient($platform);
        $marketing = $this->createUser('marketing', [$platform->id]);

        Sanctum::actingAs($marketing);

        $this->getJson("/api/crm/clients/{$client->id}/support-board/status")->assertStatus(403);
        $this->postJson("/api/crm/clients/{$client->id}/support-board/conversations/4084/reply", [
            'message' => 'Blocked message',
        ])->assertStatus(403);
    }

    public function test_sales_and_admin_can_access_all_support_board_routes(): void
    {
        $platform = $this->createPlatform([
            'support_board_sender_id' => 1,
        ]);
        $client = $this->createClient($platform);
        $this->cacheResolvedClient($client, 123);

        Http::fake(fn (ClientRequest $request) => $this->fakeSupportBoardResponse($request, 123));

        foreach ([
            $this->createUser('sales', [$platform->id], ['sb_agent_id' => 12601]),
            $this->createUser('admin'),
        ] as $user) {
            Sanctum::actingAs($user);

            $this->getJson("/api/crm/clients/{$client->id}/support-board/status")
                ->assertOk()
                ->assertJsonPath('configured', true)
                ->assertJsonPath('matched', true);

            $this->getJson("/api/crm/clients/{$client->id}/support-board/conversations")
                ->assertOk()
                ->assertJsonPath('0.id', 4084);

            $this->getJson("/api/crm/clients/{$client->id}/support-board/conversations/4084")
                ->assertOk()
                ->assertJsonPath('id', 4084)
                ->assertJsonPath('user_id', 123);

            $this->postJson("/api/crm/clients/{$client->id}/support-board/conversations/4084/reply", [
                'message' => 'Route access verification',
            ])->assertCreated()
                ->assertJsonPath('message.conversation_id', 4084);
        }
    }

    public function test_support_board_routes_enforce_market_scope_authorization(): void
    {
        $marketA = $this->createPlatform();
        $marketB = $this->createPlatform(['domain' => 'second-market.test']);
        $clientA = $this->createClient($marketA);
        $clientB = $this->createClient($marketB);
        $sales = $this->createUser('sales', [$marketA->id], ['sb_agent_id' => 12601]);

        Sanctum::actingAs($sales);

        foreach ([
            ['getJson', "/api/crm/clients/{$clientB->id}/support-board/status", []],
            ['getJson', "/api/crm/clients/{$clientB->id}/support-board/conversations", []],
            ['getJson', "/api/crm/clients/{$clientB->id}/support-board/conversations/4084", []],
            ['postJson', "/api/crm/clients/{$clientB->id}/support-board/conversations/4084/reply", ['message' => 'Unauthorized']],
        ] as [$method, $uri, $payload]) {
            $this->{$method}($uri, $payload)->assertStatus(403);
        }

        $this->cacheResolvedClient($clientA, 123);
        Http::fake(fn (ClientRequest $request) => $this->fakeSupportBoardResponse($request, 123));

        $this->getJson("/api/crm/clients/{$clientA->id}/support-board/status")->assertOk();
        $this->getJson("/api/crm/clients/{$clientA->id}/support-board/conversations")->assertOk();
        $this->getJson("/api/crm/clients/{$clientA->id}/support-board/conversations/4084")->assertOk();
        $this->postJson("/api/crm/clients/{$clientA->id}/support-board/conversations/4084/reply", [
            'message' => 'Authorized',
        ])->assertCreated();
    }

    public function test_conversation_and_reply_routes_reject_threads_owned_by_a_different_support_board_user(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createClient($platform, [
            'sb_user_id' => 123,
            'sb_matched_by' => 'phone',
        ]);
        $sales = $this->createUser('sales', [$platform->id], ['sb_agent_id' => 12601]);
        $this->cacheResolvedClient($client, 123);

        Http::fake(function (ClientRequest $request) {
            $function = $request->data()['function'] ?? null;

            return match ($function) {
                'get-conversation' => Http::response([
                    'success' => true,
                    'response' => [
                        'messages' => [],
                        'details' => [
                            'id' => '4084',
                            'user_id' => '999',
                            'conversation_status_code' => '0',
                            'conversation_time' => '2023-10-02 15:59:59',
                        ],
                    ],
                ]),
                default => Http::response(['success' => true, 'response' => []]),
            };
        });

        Sanctum::actingAs($sales);

        $this->getJson("/api/crm/clients/{$client->id}/support-board/conversations/4084")
            ->assertStatus(403);

        $this->postJson("/api/crm/clients/{$client->id}/support-board/conversations/4084/reply", [
            'message' => 'This should not send',
        ])->assertStatus(403);
    }

    public function test_sender_resolution_prefers_user_mapping_then_platform_fallback_and_returns_422_when_missing(): void
    {
        $platform = $this->createPlatform([
            'support_board_sender_id' => 1,
        ]);
        $client = $this->createClient($platform);
        $this->cacheResolvedClient($client, 123);

        $userMapped = $this->createUser('sales', [$platform->id], ['sb_agent_id' => 12601]);

        Http::fake(fn (ClientRequest $request) => $this->fakeSupportBoardResponse($request, 123));

        Sanctum::actingAs($userMapped);

        $statusResponse = $this->getJson("/api/crm/clients/{$client->id}/support-board/status");
        $statusResponse->assertOk()->assertJsonPath('can_reply', true);

        $replyResponse = $this->postJson("/api/crm/clients/{$client->id}/support-board/conversations/4084/reply", [
            'message' => 'User mapping wins',
        ]);

        $replyResponse->assertCreated()
            ->assertJsonPath('message.sender_user_id', 12601);

        Http::assertSent(function (ClientRequest $request) {
            return ($request->data()['function'] ?? null) === 'send-message'
                && (int) ($request->data()['user_id'] ?? 0) === 12601;
        });

        Http::fake(fn (ClientRequest $request) => $this->fakeSupportBoardResponse($request, 123));

        $fallbackUser = $this->createUser('sales', [$platform->id], ['sb_agent_id' => null]);
        Sanctum::actingAs($fallbackUser);

        $this->getJson("/api/crm/clients/{$client->id}/support-board/status")
            ->assertOk()
            ->assertJsonPath('can_reply', true);

        $fallbackReply = $this->postJson("/api/crm/clients/{$client->id}/support-board/conversations/4084/reply", [
            'message' => 'Platform fallback sender',
        ]);

        $fallbackReply->assertCreated()
            ->assertJsonPath('message.sender_user_id', 1);

        Http::assertSent(function (ClientRequest $request) {
            return ($request->data()['function'] ?? null) === 'send-message'
                && (int) ($request->data()['user_id'] ?? 0) === 1;
        });

        $platformWithoutSender = $this->createPlatform([
            'support_board_sender_id' => null,
        ]);
        $clientWithoutSender = $this->createClient($platformWithoutSender);
        $this->cacheResolvedClient($clientWithoutSender, 456);
        Http::fake(fn (ClientRequest $request) => $this->fakeSupportBoardResponse($request, 456));

        $noSenderUser = $this->createUser('sales', [$platformWithoutSender->id], ['sb_agent_id' => null]);
        Sanctum::actingAs($noSenderUser);

        $this->getJson("/api/crm/clients/{$clientWithoutSender->id}/support-board/status")
            ->assertOk()
            ->assertJsonPath('can_reply', false);

        $this->postJson("/api/crm/clients/{$clientWithoutSender->id}/support-board/conversations/4084/reply", [
            'message' => 'No sender configured',
        ])->assertStatus(422);
    }

    public function test_reply_creates_support_chat_note_and_timeline_event(): void
    {
        $platform = $this->createPlatform([
            'support_board_sender_id' => 77,
        ]);
        $client = $this->createClient($platform);
        $sales = $this->createUser('sales', [$platform->id], ['sb_agent_id' => 12601]);
        $this->cacheResolvedClient($client, 123);

        Http::fake(fn (ClientRequest $request) => $this->fakeSupportBoardResponse($request, 123));

        Sanctum::actingAs($sales);

        $this->postJson("/api/crm/clients/{$client->id}/support-board/conversations/4084/reply", [
            'message' => 'This reply should be tracked.',
        ])->assertCreated();

        $this->assertDatabaseHas('client_notes', [
            'client_id' => $client->id,
            'author_id' => $sales->id,
            'note_type' => 'support_chat',
            'content' => 'This reply should be tracked.',
        ]);

        $this->assertDatabaseHas('timeline_events', [
            'platform_id' => $platform->id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'support_chat_reply',
            'actor_id' => $sales->id,
        ]);
    }

    public function test_store_note_accepts_support_chat_and_contact_updates_clear_cached_link(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createClient($platform, [
            'sb_user_id' => 123,
            'sb_matched_by' => 'phone',
        ]);
        $sales = $this->createUser('sales', [$platform->id]);

        Cache::put(
            SupportBoardService::resolveCacheKey($platform->id, $client->id),
            ['matched' => true],
            now()->addHour()
        );

        Sanctum::actingAs($sales);

        $this->postJson("/api/crm/clients/{$client->id}/notes", [
            'note_type' => 'support_chat',
            'content' => 'Imported Support Board follow-up',
        ])->assertCreated();

        $this->patchJson("/api/crm/clients/{$client->id}", [
            'phone_normalized' => '254799000111',
            'email' => 'updated@example.test',
        ])->assertOk();

        $client->refresh();
        $this->assertNull($client->sb_user_id);
        $this->assertNull($client->sb_matched_by);
        $this->assertNull(Cache::get(SupportBoardService::resolveCacheKey($platform->id, $client->id)));
    }

    public function test_clients_index_supports_has_chat_filter_and_reports_with_chat_stats(): void
    {
        $platform = $this->createPlatform();
        $sales = $this->createUser('sales', [$platform->id]);

        $matchedClient = $this->createClient($platform, [
            'name' => 'Matched Client',
            'sb_user_id' => 123,
            'sb_matched_by' => 'phone',
        ]);
        $unmatchedClient = $this->createClient($platform, [
            'name' => 'Unmatched Client',
            'sb_user_id' => null,
            'sb_matched_by' => null,
        ]);

        Sanctum::actingAs($sales);

        $withChatResponse = $this->getJson('/api/crm/clients?has_chat=1');
        $withChatResponse->assertOk();
        $this->assertSame([$matchedClient->id], collect($withChatResponse->json('data'))->pluck('id')->all());

        $withoutChatResponse = $this->getJson('/api/crm/clients?has_chat=0');
        $withoutChatResponse->assertOk();
        $this->assertSame([$unmatchedClient->id], collect($withoutChatResponse->json('data'))->pluck('id')->all());

        $allResponse = $this->getJson('/api/crm/clients');
        $allResponse->assertOk()
            ->assertJsonPath('stats.with_chat', 1);
    }

    private function fakeSupportBoardResponse(ClientRequest $request, int $conversationUserId)
    {
        $function = $request->data()['function'] ?? null;

        return match ($function) {
            'get-user-conversations' => Http::response([
                'success' => true,
                'response' => [
                    [
                        'message' => 'Hello World!',
                        'message_id' => '7351',
                        'attachments' => '[["image.jpg","https://files.example.test/image.jpg"]]',
                        'payload' => '',
                        'message_status_code' => '2',
                        'last_update_time' => '2023-10-02 16:00:06',
                        'message_user_id' => '377',
                        'message_first_name' => 'Smart',
                        'message_last_name' => 'Assistant',
                        'message_profile_image' => 'https://example.test/agent.jpg',
                        'message_user_type' => 'agent',
                        'conversation_id' => '4084',
                        'conversation_user_id' => (string) $conversationUserId,
                        'conversation_status_code' => '0',
                        'conversation_creation_time' => '2023-10-02 15:59:59',
                        'department' => '1',
                        'agent_id' => '377',
                        'title' => '',
                        'source' => 'tk',
                        'extra' => null,
                        'tags' => null,
                    ],
                ],
            ]),
            'get-conversation' => Http::response([
                'success' => true,
                'response' => [
                    'messages' => [
                        [
                            'id' => '9001',
                            'user_id' => (string) $conversationUserId,
                            'message' => 'Hello!',
                            'creation_time' => '2023-10-02 15:59:59',
                            'attachments' => '',
                            'status_code' => '0',
                            'payload' => '',
                            'conversation_id' => '4084',
                            'first_name' => 'Client',
                            'last_name' => 'Example',
                            'profile_image' => 'https://example.test/client.jpg',
                            'user_type' => 'lead',
                        ],
                    ],
                    'details' => [
                        'id' => '4084',
                        'user_id' => (string) $conversationUserId,
                        'first_name' => 'Client',
                        'last_name' => 'Example',
                        'profile_image' => 'https://example.test/client.jpg',
                        'user_type' => 'lead',
                        'title' => '',
                        'conversation_time' => '2023-10-02 15:59:59',
                        'conversation_status_code' => '0',
                        'department' => '1',
                    ],
                ],
            ]),
            'send-message' => Http::response([
                'success' => true,
                'response' => [
                    'human_takeover_active' => true,
                    'id' => 7777,
                    'queue' => false,
                    'notifications' => ['email'],
                    'message' => $request->data()['message'] ?? '',
                ],
            ]),
            default => Http::response(['success' => true, 'response' => []]),
        };
    }

    private function cacheResolvedClient(Client $client, int $sbUserId, string $matchedBy = 'phone'): void
    {
        $client->forceFill([
            'sb_user_id' => $sbUserId,
            'sb_matched_by' => $matchedBy,
        ])->saveQuietly();

        Cache::put(
            SupportBoardService::resolveCacheKey((int) $client->platform_id, (int) $client->id),
            [
                'matched' => true,
                'sb_user' => [
                    'id' => $sbUserId,
                    'first_name' => 'Jane',
                    'last_name' => 'Visitor',
                    'full_name' => 'Jane Visitor',
                    'email' => $client->email,
                    'profile_image' => null,
                    'user_type' => 'lead',
                    'details' => [],
                ],
                'matched_by' => $matchedBy,
                'tried' => [
                    'phone' => [$client->phone_normalized],
                    'email' => $client->email,
                ],
            ],
            now()->addHour()
        );
    }

    private function createPlatform(array $attributes = []): Platform
    {
        return Platform::factory()->create(array_merge([
            'is_active' => false,
            'support_board_api_url' => 'https://cloud.board.support/script/include/api.php',
            'support_board_token' => 'test-support-board-token',
            'support_board_sender_id' => null,
        ], $attributes));
    }

    private function createClient(Platform $platform, array $attributes = []): Client
    {
        return Client::factory()->create(array_merge([
            'platform_id' => $platform->id,
        ], $attributes));
    }

    private function createUser(string $role, array $assignedMarkets = [], array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'name' => ucfirst($role) . ' User',
            'email' => $role . '-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => $assignedMarkets,
            'sb_agent_id' => null,
        ], $attributes));
    }
}
