<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Platform;
use App\Models\SupportBoardSyncRun;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Jobs\RunSupportBoardSyncJob;
use App\Services\SupportBoardLinkSyncService;
use App\Services\SupportBoardSyncRunService;
use App\Services\SupportBoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
        $this->getJson("/api/crm/clients/{$client->id}/support-board/profile")->assertStatus(403);
        $this->postJson("/api/crm/clients/{$client->id}/support-board/profile-sync/preview", [
            'direction' => 'support_board_to_crm',
            'mode' => 'fill_blanks',
            'fields' => ['email'],
        ])->assertStatus(403);
        $this->postJson("/api/crm/clients/{$client->id}/support-board/profile-sync/apply", [
            'direction' => 'support_board_to_crm',
            'mode' => 'fill_blanks',
            'fields' => ['email'],
            'reason' => 'Blocked profile sync',
        ])->assertStatus(403);
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

            $this->getJson("/api/crm/clients/{$client->id}/support-board/profile")
                ->assertOk()
                ->assertJsonPath('configured', true)
                ->assertJsonPath('matched', true)
                ->assertJsonPath('sb_user.id', 123);

            $this->postJson("/api/crm/clients/{$client->id}/support-board/profile-sync/preview", [
                'direction' => 'support_board_to_crm',
                'mode' => 'fill_blanks',
                'fields' => ['email', 'phone'],
            ])->assertOk()
                ->assertJsonPath('direction', 'support_board_to_crm');

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
            ['getJson', "/api/crm/clients/{$clientB->id}/support-board/profile", []],
            ['getJson', "/api/crm/clients/{$clientB->id}/support-board/conversations", []],
            ['getJson', "/api/crm/clients/{$clientB->id}/support-board/conversations/4084", []],
            ['postJson', "/api/crm/clients/{$clientB->id}/support-board/profile-sync/preview", [
                'direction' => 'support_board_to_crm',
                'mode' => 'fill_blanks',
                'fields' => ['email'],
            ]],
            ['postJson', "/api/crm/clients/{$clientB->id}/support-board/profile-sync/apply", [
                'direction' => 'support_board_to_crm',
                'mode' => 'fill_blanks',
                'fields' => ['email'],
                'reason' => 'Unauthorized',
            ]],
            ['postJson', "/api/crm/clients/{$clientB->id}/support-board/conversations/4084/reply", ['message' => 'Unauthorized']],
        ] as [$method, $uri, $payload]) {
            $this->{$method}($uri, $payload)->assertStatus(403);
        }

        $this->cacheResolvedClient($clientA, 123);
        Http::fake(fn (ClientRequest $request) => $this->fakeSupportBoardResponse($request, 123));

        $this->getJson("/api/crm/clients/{$clientA->id}/support-board/status")->assertOk();
        $this->getJson("/api/crm/clients/{$clientA->id}/support-board/profile")->assertOk();
        $this->postJson("/api/crm/clients/{$clientA->id}/support-board/profile-sync/preview", [
            'direction' => 'support_board_to_crm',
            'mode' => 'fill_blanks',
            'fields' => ['email'],
        ])->assertOk();
        $this->getJson("/api/crm/clients/{$clientA->id}/support-board/conversations")->assertOk();
        $this->getJson("/api/crm/clients/{$clientA->id}/support-board/conversations/4084")->assertOk();
        $this->postJson("/api/crm/clients/{$clientA->id}/support-board/conversations/4084/reply", [
            'message' => 'Authorized',
        ])->assertCreated();
    }

    public function test_profile_route_returns_metadata_and_preview_highlights_safe_changes(): void
    {
        $platform = $this->createPlatform([
            'phone_prefix' => '255',
            'country' => 'Kenya',
        ]);
        $client = $this->createClient($platform, [
            'name' => 'Butter',
            'email' => null,
            'phone_normalized' => null,
            'city' => null,
            'sb_user_id' => 24416,
            'sb_matched_by' => 'phone',
        ]);
        $sales = $this->createUser('sales', [$platform->id], ['sb_agent_id' => 12601]);
        $this->cacheResolvedClient($client, 24416);

        Http::fake(function (ClientRequest $request) {
            $function = $request->data()['function'] ?? null;

            return match ($function) {
                'get-user' => Http::response([
                    'success' => true,
                    'response' => [
                        'id' => 24416,
                        'first_name' => 'Lovenness',
                        'last_name' => '',
                        'email' => 'lovenessjj63@gmail.com',
                        'user_type' => 'user',
                        'creation_time' => '2026-03-19 07:05:00',
                        'last_activity' => '2026-03-20 10:07:00',
                        'details' => [
                            ['slug' => 'phone', 'name' => 'Phone', 'value' => '+255710103849'],
                            ['slug' => 'country_code', 'name' => 'Country code', 'value' => 'TZ'],
                            ['slug' => 'currency', 'name' => 'Currency', 'value' => 'TZS'],
                            ['slug' => 'location', 'name' => 'Location', 'value' => 'Dar es Salaam, Tanzania'],
                            ['slug' => 'current_url', 'name' => 'Current URL', 'value' => 'https://www.exotic-tz.net/chat/'],
                            ['slug' => 'time_zone', 'name' => 'Timezone', 'value' => 'Africa/Dar_es_Salaam'],
                            ['slug' => 'browser_language', 'name' => 'Browser language', 'value' => 'en-GB'],
                        ],
                    ],
                ]),
                default => Http::response(['success' => true, 'response' => []]),
            };
        });

        Sanctum::actingAs($sales);

        $this->getJson("/api/crm/clients/{$client->id}/support-board/profile")
            ->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('matched', true)
            ->assertJsonPath('sb_user.id', 24416)
            ->assertJsonPath('suggestions.market.country_name', 'Tanzania')
            ->assertJsonPath('suggestions.city.value', 'Dar es Salaam')
            ->assertJsonFragment([
                'slug' => 'phone',
                'value' => '+255710103849',
            ]);

        $this->postJson("/api/crm/clients/{$client->id}/support-board/profile-sync/preview", [
            'direction' => 'support_board_to_crm',
            'mode' => 'fill_blanks',
            'fields' => ['name', 'email', 'phone', 'city'],
        ])->assertOk()
            ->assertJsonPath('counts.applyable', 3)
            ->assertJsonPath('counts.conflicts', 1)
            ->assertJsonFragment([
                'field' => 'name',
                'outcome' => 'conflict',
            ])
            ->assertJsonFragment([
                'field' => 'email',
                'outcome' => 'fill',
            ])
            ->assertJsonFragment([
                'field' => 'phone',
                'outcome' => 'fill',
            ])
            ->assertJsonFragment([
                'field' => 'city',
                'outcome' => 'fill',
            ]);
    }

    public function test_apply_profile_sync_updates_client_and_logs_timeline_and_audit(): void
    {
        $platform = $this->createPlatform([
            'phone_prefix' => '255',
        ]);
        $client = $this->createClient($platform, [
            'name' => 'Butter',
            'email' => null,
            'phone_normalized' => null,
            'city' => null,
            'sb_user_id' => 24416,
            'sb_matched_by' => 'phone',
        ]);
        $sales = $this->createUser('sales', [$platform->id], ['sb_agent_id' => 12601]);
        $this->cacheResolvedClient($client, 24416);

        Http::fake(function (ClientRequest $request) {
            $function = $request->data()['function'] ?? null;

            return match ($function) {
                'get-user' => Http::response([
                    'success' => true,
                    'response' => [
                        'id' => 24416,
                        'first_name' => 'Lovenness',
                        'last_name' => '',
                        'email' => 'lovenessjj63@gmail.com',
                        'user_type' => 'user',
                        'creation_time' => '2026-03-19 07:05:00',
                        'last_activity' => '2026-03-20 10:07:00',
                        'details' => [
                            ['slug' => 'phone', 'name' => 'Phone', 'value' => '+255710103849'],
                            ['slug' => 'location', 'name' => 'Location', 'value' => 'Dar es Salaam, Tanzania'],
                            ['slug' => 'country_code', 'name' => 'Country code', 'value' => 'TZ'],
                        ],
                    ],
                ]),
                default => Http::response(['success' => true, 'response' => []]),
            };
        });

        Sanctum::actingAs($sales);

        $this->postJson("/api/crm/clients/{$client->id}/support-board/profile-sync/apply", [
            'direction' => 'support_board_to_crm',
            'mode' => 'fill_blanks',
            'fields' => ['email', 'phone', 'city'],
            'reason' => 'Backfill CRM profile from matched Support Board contact',
        ])->assertOk()
            ->assertJsonPath('sync.applied_count', 3)
            ->assertJsonPath('sync.changed_fields.0', 'email');

        $client->refresh();

        $this->assertSame('lovenessjj63@gmail.com', $client->email);
        $this->assertSame('255710103849', $client->phone_normalized);
        $this->assertSame('Dar es Salaam', $client->city);
        $this->assertSame(24416, (int) $client->sb_user_id);

        $this->assertDatabaseHas('timeline_events', [
            'platform_id' => $platform->id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'support_board_profile_sync',
            'actor_id' => $sales->id,
        ]);

        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'actor_id' => $sales->id,
            'action' => 'client_support_board_profile_sync',
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'reason' => 'Backfill CRM profile from matched Support Board contact',
        ]);
    }

    public function test_apply_profile_sync_can_push_selected_crm_fields_to_support_board(): void
    {
        $platform = $this->createPlatform([
            'phone_prefix' => '254',
        ]);
        $client = $this->createClient($platform, [
            'name' => 'CRM Client',
            'email' => 'crm-client@example.test',
            'phone_normalized' => '254799000111',
            'city' => 'Nairobi',
            'sb_user_id' => 24416,
            'sb_matched_by' => 'phone',
        ]);
        $admin = $this->createUser('admin');
        $this->cacheResolvedClient($client, 24416);

        Http::fake(function (ClientRequest $request) {
            $function = $request->data()['function'] ?? null;

            return match ($function) {
                'get-user' => Http::response([
                    'success' => true,
                    'response' => [
                        'id' => 24416,
                        'first_name' => '',
                        'last_name' => '',
                        'email' => '',
                        'user_type' => 'user',
                        'details' => [
                            ['slug' => 'phone', 'name' => 'Phone', 'value' => null],
                            ['slug' => 'city', 'name' => 'City', 'value' => null],
                        ],
                    ],
                ]),
                'update-user' => Http::response([
                    'success' => true,
                    'response' => true,
                ]),
                default => Http::response(['success' => true, 'response' => []]),
            };
        });

        Sanctum::actingAs($admin);

        $this->postJson("/api/crm/clients/{$client->id}/support-board/profile-sync/apply", [
            'direction' => 'crm_to_support_board',
            'mode' => 'fill_blanks',
            'fields' => ['name', 'email', 'phone', 'city'],
            'reason' => 'Push the cleaned CRM profile back to Support Board',
        ])->assertOk()
            ->assertJsonPath('sync.applied_count', 4);

        Http::assertSent(function (ClientRequest $request) {
            if (($request->data()['function'] ?? null) !== 'update-user') {
                return false;
            }

            $settingsExtra = json_decode((string) ($request->data()['settings_extra'] ?? ''), true);

            return (int) ($request->data()['user_id'] ?? 0) === 24416
                && ($request->data()['first_name'] ?? null) === 'CRM'
                && ($request->data()['last_name'] ?? null) === 'Client'
                && ($request->data()['email'] ?? null) === 'crm-client@example.test'
                && is_array($settingsExtra)
                && ($settingsExtra['phone'][0] ?? null) === '254799000111'
                && ($settingsExtra['city'][0] ?? null) === 'Nairobi';
        });
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

    public function test_settings_support_board_sync_endpoint_queues_background_run_for_selected_market(): void
    {
        config(['queue.default' => 'database']);
        $platform = $this->createPlatform();
        $otherPlatform = $this->createPlatform(['domain' => 'second-market.test']);
        $admin = $this->createUser('admin');

        $matchedClient = $this->createClient($platform, [
            'name' => 'Already Matched',
            'phone_normalized' => '254700000001',
            'sb_user_id' => 999,
            'sb_matched_by' => 'phone',
        ]);
        $unmatchedClient = $this->createClient($platform, [
            'name' => 'Needs Link',
            'phone_normalized' => '254700111222',
            'email' => 'needs-link@example.test',
        ]);
        $otherMarketClient = $this->createClient($otherPlatform, [
            'name' => 'Other Market',
            'phone_normalized' => '254700333444',
            'email' => 'other-market@example.test',
        ]);

        Queue::fake();

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/crm/settings/integrations/platforms/{$platform->id}/support-board/sync", [
            'refresh' => false,
            'reason' => 'Backfill unmatched Support Board links',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('run.origin', 'manual')
            ->assertJsonPath('run.mode', 'incremental')
            ->assertJsonPath('run.candidates', 1)
            ->assertJsonPath('run.processed', 0)
            ->assertJsonPath('run.errors', 0);

        Queue::assertPushed(RunSupportBoardSyncJob::class, function (RunSupportBoardSyncJob $job) use ($platform) {
            $run = SupportBoardSyncRun::query()->find($job->runId);

            return $run
                && (int) $run->platform_id === (int) $platform->id
                && $run->status === SupportBoardSyncRun::STATUS_QUEUED;
        });

        $matchedClient->refresh();
        $unmatchedClient->refresh();
        $otherMarketClient->refresh();

        $this->assertSame(999, (int) $matchedClient->sb_user_id);
        $this->assertNull($unmatchedClient->sb_user_id);
        $this->assertNull($otherMarketClient->sb_user_id);
    }

    public function test_settings_support_board_sync_endpoint_reuses_existing_active_run(): void
    {
        config(['queue.default' => 'database']);
        $platform = $this->createPlatform();
        $admin = $this->createUser('admin');

        $run = SupportBoardSyncRun::query()->create([
            'platform_id' => $platform->id,
            'initiated_by' => $admin->id,
            'mode' => 'incremental',
            'status' => SupportBoardSyncRun::STATUS_RUNNING,
            'candidates' => 12,
            'processed' => 4,
            'matched' => 3,
            'errors' => 0,
            'started_at' => now()->subMinutes(3),
            'last_heartbeat_at' => now()->subSeconds(20),
        ]);

        Queue::fake();
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/crm/settings/integrations/platforms/{$platform->id}/support-board/sync", [
            'refresh' => false,
            'reason' => 'Avoid duplicate sync run',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('reused_run', true)
            ->assertJsonPath('run.id', $run->id)
            ->assertJsonPath('run.status', 'running');

        Queue::assertNothingPushed();
    }

    public function test_latest_support_board_sync_endpoint_returns_run_status(): void
    {
        config(['queue.default' => 'database']);
        $platform = $this->createPlatform();
        $admin = $this->createUser('admin');

        $run = SupportBoardSyncRun::query()->create([
            'platform_id' => $platform->id,
            'initiated_by' => $admin->id,
            'mode' => 'refresh',
            'status' => SupportBoardSyncRun::STATUS_COMPLETED,
            'candidates' => 10,
            'processed' => 10,
            'matched' => 7,
            'updated' => 1,
            'cleared' => 0,
            'unchanged' => 2,
            'errors' => 0,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinute(),
            'last_heartbeat_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/crm/settings/integrations/platforms/{$platform->id}/support-board/sync/latest")
            ->assertOk()
            ->assertJsonPath('run.id', $run->id)
            ->assertJsonPath('run.origin', 'manual')
            ->assertJsonPath('run.status', 'completed')
            ->assertJsonPath('run.progress_percent', 100)
            ->assertJsonPath('run.refresh', true);
    }

    public function test_support_board_sync_job_processes_run_to_completion(): void
    {
        config(['queue.default' => 'database']);
        $platform = $this->createPlatform();
        $admin = $this->createUser('admin');
        $client = $this->createClient($platform, [
            'phone_normalized' => '254701234567',
            'email' => 'async-sync@example.test',
        ]);

        Http::fake(function (ClientRequest $request) {
            $function = $request->data()['function'] ?? null;
            if ($function === 'get-users-with-details') {
                return Http::response([
                    'success' => true,
                    'response' => [
                        'phone' => [
                            ['id' => 5678, 'value' => '+254701234567'],
                        ],
                        'email' => [
                            ['id' => 5678, 'value' => 'async-sync@example.test'],
                        ],
                    ],
                ]);
            }

            return Http::response(['success' => true, 'response' => []]);
        });

        $started = app(SupportBoardSyncRunService::class)->startRun(
            $platform,
            $admin,
            false,
            'Run one-client async sync'
        );

        (new RunSupportBoardSyncJob((int) $started['run']->id))
            ->handle(
                app(SupportBoardSyncRunService::class),
                app(SupportBoardLinkSyncService::class)
            );

        $client->refresh();
        $run = SupportBoardSyncRun::query()->findOrFail($started['run']->id);

        $this->assertSame(5678, (int) $client->sb_user_id);
        $this->assertSame('phone', $client->sb_matched_by);
        $this->assertSame(SupportBoardSyncRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, (int) $run->processed);
        $this->assertSame(1, (int) $run->matched);
    }

    public function test_support_board_sync_job_processes_runs_in_bounded_chunks(): void
    {
        config(['queue.default' => 'database']);
        $platform = $this->createPlatform();
        $admin = $this->createUser('admin');

        $clients = collect();
        for ($index = 1; $index <= 101; $index++) {
            $phone = sprintf('254700%06d', $index);

            $clients->push($this->createClient($platform, [
                'name' => "Chunk Client {$index}",
                'phone_normalized' => $phone,
                'email' => "chunk-{$index}@example.test",
            ]));
        }

        Http::fake(function (ClientRequest $request) use ($clients) {
            $function = $request->data()['function'] ?? null;
            if ($function === 'get-users-with-details') {
                return Http::response([
                    'success' => true,
                    'response' => [
                        'phone' => $clients->map(fn ($client, $index) => [
                            'id' => 7000 + $index + 1,
                            'value' => '+' . $client->phone_normalized,
                        ])->all(),
                        'email' => $clients->map(fn ($client, $index) => [
                            'id' => 7000 + $index + 1,
                            'value' => $client->email,
                        ])->all(),
                    ],
                ]);
            }

            return Http::response(['success' => true, 'response' => []]);
        });

        $started = app(SupportBoardSyncRunService::class)->startRun(
            $platform,
            $admin,
            false,
            'Run chunked async sync'
        );

        Queue::fake();

        (new RunSupportBoardSyncJob((int) $started['run']->id))
            ->handle(
                app(SupportBoardSyncRunService::class),
                app(SupportBoardLinkSyncService::class)
            );

        $run = SupportBoardSyncRun::query()->findOrFail($started['run']->id);
        $hundredthClient = $clients->values()[99]->fresh();
        $lastClient = $clients->last()->fresh();

        $this->assertSame(SupportBoardSyncRun::STATUS_RUNNING, $run->status);
        $this->assertSame(100, (int) $run->processed);
        $this->assertSame(100, (int) $run->matched);
        $this->assertSame((int) $hundredthClient->id, (int) $run->last_processed_client_id);
        $this->assertNotNull($hundredthClient->sb_user_id);
        $this->assertNull($lastClient->sb_user_id);

        Queue::assertPushed(RunSupportBoardSyncJob::class, function (RunSupportBoardSyncJob $job) use ($run) {
            return (int) $job->runId === (int) $run->id;
        });
    }

    public function test_settings_support_board_sync_endpoint_rejects_sales_role(): void
    {
        $platform = $this->createPlatform();
        $sales = $this->createUser('sales', [$platform->id]);

        Sanctum::actingAs($sales);

        $this->postJson("/api/crm/settings/integrations/platforms/{$platform->id}/support-board/sync", [
            'refresh' => false,
            'reason' => 'Sales should not run this',
        ])->assertStatus(403);
    }

    public function test_support_board_sync_command_queues_scheduler_owned_run_for_selected_platform(): void
    {
        config(['queue.default' => 'database']);
        $platform = $this->createPlatform();
        $otherPlatform = $this->createPlatform([
            'domain' => 'other-market.test',
        ]);
        $client = $this->createClient($platform, [
            'phone_normalized' => '254701234567',
            'email' => 'command-sync@example.test',
        ]);
        $otherClient = $this->createClient($otherPlatform, [
            'phone_normalized' => '254701111111',
            'email' => 'other-command-sync@example.test',
        ]);

        Queue::fake();

        $this->artisan("crm:sync-sb-users --platform={$platform->id}")
            ->assertExitCode(0);

        $client->refresh();
        $otherClient->refresh();

        $run = SupportBoardSyncRun::query()->latest('id')->first();

        $this->assertNotNull($run);
        $this->assertSame((int) $platform->id, (int) $run->platform_id);
        $this->assertNull($run->initiated_by);
        $this->assertSame('Scheduled Support Board link sync', $run->reason);
        $this->assertNull($client->sb_user_id);
        $this->assertNull($otherClient->sb_user_id);

        Queue::assertPushed(RunSupportBoardSyncJob::class, function (RunSupportBoardSyncJob $job) use ($run) {
            return (int) $job->runId === (int) $run->id;
        });

        $serializedRun = app(SupportBoardSyncRunService::class)->serializeRun($run);
        $this->assertSame('scheduler', $serializedRun['origin']);
    }

    public function test_support_board_sync_command_reuses_existing_active_scheduler_run(): void
    {
        config(['queue.default' => 'database']);
        $platform = $this->createPlatform();
        $this->createClient($platform, [
            'phone_normalized' => '254701234567',
            'email' => 'command-sync@example.test',
        ]);

        $run = SupportBoardSyncRun::query()->create([
            'platform_id' => $platform->id,
            'initiated_by' => null,
            'mode' => 'incremental',
            'status' => SupportBoardSyncRun::STATUS_RUNNING,
            'candidates' => 1,
            'processed' => 0,
            'matched' => 0,
            'updated' => 0,
            'cleared' => 0,
            'unchanged' => 0,
            'errors' => 0,
            'started_at' => now()->subMinute(),
            'last_heartbeat_at' => now()->subSeconds(15),
            'reason' => 'Scheduled Support Board link sync',
        ]);

        Queue::fake();

        $this->artisan("crm:sync-sb-users --platform={$platform->id}")
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertSame(1, SupportBoardSyncRun::query()->count());
        $this->assertSame((int) $run->id, (int) SupportBoardSyncRun::query()->first()->id);
    }

    public function test_support_board_sync_command_skips_duplicate_invocation_when_lock_exists(): void
    {
        $lock = Cache::lock('crm:sync-sb-users', 600);

        $this->assertTrue($lock->get());

        try {
            Http::fake();

            $this->artisan('crm:sync-sb-users')
                ->expectsOutput('Support Board sync is already running. Skipping duplicate invocation.')
                ->assertExitCode(0);

            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    private function fakeSupportBoardResponse(ClientRequest $request, int $conversationUserId)
    {
        $function = $request->data()['function'] ?? null;

        return match ($function) {
            'get-user' => Http::response([
                'success' => true,
                'response' => [
                    'id' => $conversationUserId,
                    'first_name' => 'Client',
                    'last_name' => 'Example',
                    'email' => 'client@example.test',
                    'user_type' => 'lead',
                    'creation_time' => '2026-03-19 07:05:00',
                    'last_activity' => '2026-03-20 10:07:00',
                    'details' => [
                        ['slug' => 'phone', 'name' => 'Phone', 'value' => '+254700000000'],
                        ['slug' => 'country_code', 'name' => 'Country code', 'value' => 'KE'],
                        ['slug' => 'location', 'name' => 'Location', 'value' => 'Nairobi, Kenya'],
                    ],
                ],
            ]),
            'get-user-extra' => Http::response([
                'success' => true,
                'response' => [
                    ['slug' => 'phone', 'name' => 'Phone', 'value' => '+254700000000'],
                    ['slug' => 'country_code', 'name' => 'Country code', 'value' => 'KE'],
                    ['slug' => 'location', 'name' => 'Location', 'value' => 'Nairobi, Kenya'],
                ],
            ]),
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
            'get-user-by' => Http::response([
                'success' => true,
                'response' => [
                    'id' => $conversationUserId,
                    'first_name' => 'Client',
                    'last_name' => 'Example',
                    'email' => 'client@example.test',
                    'user_type' => 'lead',
                    'details' => [],
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
