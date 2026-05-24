<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Lead;
use App\Models\MessagingSuppression;
use App\Models\MessagingWebhookEvent;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppProviderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessagingPhaseThreeTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_webhook_verify_challenge_uses_profile_token(): void
    {
        User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $platform = Platform::factory()->create();
        $this->createProfile($platform);

        $this->get('/api/crm/messaging/webhook/meta?hub.mode=subscribe&hub.verify_token=verify-token&hub.challenge=abc123')
            ->assertOk()
            ->assertSee('abc123', false);

        $this->get('/api/crm/messaging/webhook/meta?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=abc123')
            ->assertForbidden();
    }

    public function test_meta_webhook_rejects_invalid_signature(): void
    {
        User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $platform = Platform::factory()->create();
        $this->createProfile($platform);

        $payload = $this->inboundPayload('wamid.inbound-1', '254748612016', 'Hello');
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->postSignedMetaWebhook($rawBody, 'wrong-secret')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'webhook_verification_failed');

        $this->assertSame(0, WhatsAppMessage::count());
        $this->assertSame(0, MessagingWebhookEvent::count());
    }

    public function test_meta_inbound_stop_records_message_suppression_timeline_audit_and_dedupes_replay(): void
    {
        User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $platform = Platform::factory()->create();
        $this->createProfile($platform);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'phone_normalized' => '254748612016',
        ]);

        $payload = $this->inboundPayload('wamid.stop-1', '254748612016', 'STOP');
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->postSignedMetaWebhook($rawBody)
            ->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('duplicates', 0);

        $message = WhatsAppMessage::firstOrFail();
        $this->assertSame('inbound', $message->direction);
        $this->assertSame('received', $message->status);
        $this->assertSame('254748612016', $message->phone_e164);
        $this->assertSame('STOP', $message->body);
        $this->assertSame($client->id, $message->client_id);

        $suppression = MessagingSuppression::firstOrFail();
        $this->assertSame($platform->id, $suppression->platform_id);
        $this->assertSame('254748612016', $suppression->phone_e164);
        $this->assertSame('whatsapp', $suppression->channel);
        $this->assertSame('keyword_stop', $suppression->reason);
        $this->assertSame($message->id, $suppression->source_message_id);

        $this->assertDatabaseHas('messaging_webhook_events', [
            'engine' => 'meta_cloud_api',
            'external_event_id' => 'message:wamid.stop-1',
        ]);
        $this->assertDatabaseHas('timeline_events', [
            'platform_id' => $platform->id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'whatsapp_inbound_received',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'action' => 'whatsapp_inbound_received',
            'entity_type' => 'whatsapp_message',
            'entity_id' => $message->id,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'action' => 'messaging_opt_out_recorded',
            'entity_type' => 'messaging_suppression',
            'entity_id' => $suppression->id,
        ]);

        $this->postSignedMetaWebhook($rawBody)
            ->assertOk()
            ->assertJsonPath('processed', 0)
            ->assertJsonPath('duplicates', 1);

        $this->assertSame(1, WhatsAppMessage::count());
        $this->assertSame(1, MessagingSuppression::count());
        $this->assertSame(1, MessagingWebhookEvent::count());
    }

    public function test_meta_status_update_updates_outbound_message_and_records_timeline(): void
    {
        User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $platform = Platform::factory()->create();
        $profile = $this->createProfile($platform);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'phone_normalized' => '254748612016',
        ]);
        $message = WhatsAppMessage::create([
            'platform_id' => $platform->id,
            'direction' => 'outbound',
            'engine' => 'meta_cloud_api',
            'provider_profile_id' => $profile->id,
            'client_id' => $client->id,
            'phone_e164' => '254748612016',
            'body' => 'Hello',
            'provider_message_id' => 'wamid.outbound-1',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $payload = $this->statusPayload('wamid.outbound-1', 'delivered');
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->postSignedMetaWebhook($rawBody)
            ->assertOk()
            ->assertJsonPath('processed', 1);

        $message->refresh();
        $this->assertSame('delivered', $message->status);
        $this->assertNotNull($message->delivered_at);
        $this->assertDatabaseHas('timeline_events', [
            'platform_id' => $platform->id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'whatsapp_delivered',
        ]);
        $this->assertDatabaseHas('messaging_webhook_events', [
            'engine' => 'meta_cloud_api',
            'external_event_id' => 'status:wamid.outbound-1:delivered',
        ]);
    }

    public function test_meta_inbound_links_to_lead_and_exposes_lead_inbound_count(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $platform = Platform::factory()->create();
        $this->createProfile($platform);
        $lead = Lead::create([
            'platform_id' => $platform->id,
            'name' => 'Lead With WhatsApp',
            'phone_normalized' => '254748612017',
            'source' => 'registration',
            'status' => 'new',
        ]);

        $payload = $this->inboundPayload('wamid.lead-1', '254748612017', 'Interested');
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->postSignedMetaWebhook($rawBody)
            ->assertOk()
            ->assertJsonPath('processed', 1);

        $message = WhatsAppMessage::firstOrFail();
        $this->assertSame($lead->id, $message->lead_id);
        $this->assertNull($message->client_id);
        $this->assertDatabaseHas('timeline_events', [
            'platform_id' => $platform->id,
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'event_type' => 'whatsapp_inbound_received',
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/crm/leads?search=254748612017')
            ->assertOk()
            ->assertJsonPath('data.0.id', $lead->id)
            ->assertJsonPath('data.0.whatsapp_inbound_count', 1);
    }

    public function test_admin_can_list_and_revoke_whatsapp_suppression(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $platform = Platform::factory()->create();
        Sanctum::actingAs($admin);

        $suppression = MessagingSuppression::create([
            'platform_id' => $platform->id,
            'phone_e164' => '254748612016',
            'channel' => 'whatsapp',
            'reason' => 'keyword_stop',
            'opted_out_at' => now(),
        ]);

        $this->getJson('/api/crm/messaging/suppressions?channel=whatsapp')
            ->assertOk()
            ->assertJsonPath('data.0.id', $suppression->id)
            ->assertJsonPath('data.0.channel', 'whatsapp');

        $this->postJson("/api/crm/messaging/suppressions/{$suppression->id}/revoke")
            ->assertOk()
            ->assertJsonPath('id', $suppression->id)
            ->assertJsonPath('revoked_by.id', $admin->id);

        $suppression->refresh();
        $this->assertNotNull($suppression->revoked_at);
        $this->assertSame($admin->id, $suppression->revoked_by);
        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'actor_id' => $admin->id,
            'action' => 'messaging_opt_out_revoked',
            'entity_type' => 'messaging_suppression',
            'entity_id' => $suppression->id,
        ]);
    }

    private function createProfile(Platform $platform, array $overrides = []): WhatsAppProviderProfile
    {
        return WhatsAppProviderProfile::create(array_merge([
            'market_id' => $platform->id,
            'engine' => 'meta_cloud_api',
            'profile_name' => 'Meta Sandbox',
            'environment' => 'sandbox',
            'kill_switch_enabled' => false,
            'meta_phone_number_id' => '123456789',
            'meta_business_account_id' => '987654321',
            'meta_access_token' => 'test-token',
            'meta_webhook_verify_token' => 'verify-token',
            'meta_app_secret' => 'app-secret',
            'meta_api_version' => 'v25.0',
            'active' => true,
        ], $overrides));
    }

    private function postSignedMetaWebhook(string $rawBody, string $secret = 'app-secret')
    {
        return $this->call('POST', '/api/crm/messaging/webhook/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $rawBody, $secret),
        ], $rawBody);
    }

    private function inboundPayload(string $messageId, string $from, string $body): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => [
                            'display_phone_number' => '254700000000',
                            'phone_number_id' => '123456789',
                        ],
                        'contacts' => [[
                            'wa_id' => $from,
                            'profile' => ['name' => 'Test Customer'],
                        ]],
                        'messages' => [[
                            'from' => $from,
                            'id' => $messageId,
                            'timestamp' => (string) now()->timestamp,
                            'text' => ['body' => $body],
                            'type' => 'text',
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function statusPayload(string $messageId, string $status): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => [
                            'display_phone_number' => '254700000000',
                            'phone_number_id' => '123456789',
                        ],
                        'statuses' => [[
                            'id' => $messageId,
                            'status' => $status,
                            'timestamp' => (string) now()->timestamp,
                            'recipient_id' => '254748612016',
                        ]],
                    ],
                ]],
            ]],
        ];
    }
}
