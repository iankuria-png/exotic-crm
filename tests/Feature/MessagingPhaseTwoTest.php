<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\MessagingSuppression;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Models\Template;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppProviderProfile;
use App\Models\WhatsAppRoutingRule;
use App\Services\Messaging\MessageRecipient;
use App\Services\Messaging\MessagingDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessagingPhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_meta_profile_without_exposing_secrets(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $platform = Platform::factory()->create();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/crm/messaging/whatsapp/profiles', [
            'market_id' => $platform->id,
            'profile_name' => 'Meta Sandbox',
            'environment' => 'sandbox',
            'meta_phone_number_id' => '1234567890',
            'meta_business_account_id' => '987654321',
            'meta_access_token' => 'plain-token',
            'meta_webhook_verify_token' => 'verify-me',
            'meta_app_secret' => 'app-secret',
            'meta_api_version' => 'v25.0',
            'active' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('meta_access_token_configured', true)
            ->assertJsonMissingPath('meta_access_token');

        $profile = WhatsAppProviderProfile::firstOrFail();
        $raw = DB::table('whatsapp_provider_profiles')->where('id', $profile->id)->first();

        $this->assertSame('plain-token', $profile->meta_access_token);
        $this->assertNotSame('plain-token', $raw->meta_access_token);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'whatsapp_profile_updated',
            'entity_type' => 'whatsapp_provider_profile',
            'entity_id' => $profile->id,
        ]);
    }

    public function test_template_authoring_accepts_whatsapp_channel_in_phase_two(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $platform = Platform::factory()->create();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/crm/settings/templates', [
            'platform_id' => $platform->id,
            'title' => 'WhatsApp payment reminder',
            'category' => 'payment',
            'channel' => 'whatsapp',
            'body' => 'Your payment link is ready.',
            'status' => 'draft',
        ]);

        $response->assertCreated()->assertJsonPath('channel', 'whatsapp');
        $this->assertSame('whatsapp', Template::firstOrFail()->channel);
    }

    public function test_dispatcher_sends_meta_text_and_records_message_audit_and_timeline(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'phone_normalized' => '0748612016',
        ]);
        $profile = $this->createProfile($platform);
        WhatsAppRoutingRule::create([
            'market_id' => $platform->id,
            'message_type' => 'conversation',
            'primary_profile_id' => $profile->id,
            'fallback_to_sms' => true,
            'enabled' => true,
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.test-1']],
            ], 200),
        ]);

        $result = app(MessagingDispatcher::class)->dispatch(
            MessageRecipient::fromClient($client),
            'Hello from Exotic CRM',
            'whatsapp',
            [
                'message_type' => 'conversation',
                'actor_id' => $admin->id,
                'idempotency_key' => 'conversation-test-1',
            ]
        );

        $this->assertTrue($result->success);
        $message = WhatsAppMessage::firstOrFail();
        $this->assertSame('sent', $message->status);
        $this->assertSame('wamid.test-1', $message->provider_message_id);
        $this->assertSame($client->id, $message->client_id);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v25.0/123456789/messages'
                && $request['messaging_product'] === 'whatsapp'
                && $request['to'] === '254748612016'
                && $request['type'] === 'text'
                && $request['text']['body'] === 'Hello from Exotic CRM';
        });

        $this->assertDatabaseHas('timeline_events', [
            'platform_id' => $platform->id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'whatsapp_sent',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'actor_id' => $admin->id,
            'action' => 'whatsapp_sent',
            'entity_type' => 'whatsapp_message',
            'entity_id' => $message->id,
        ]);
    }

    public function test_kill_switch_blocks_send_before_meta_http_call(): void
    {
        $platform = Platform::factory()->create();
        $profile = $this->createProfile($platform, ['kill_switch_enabled' => true]);
        WhatsAppRoutingRule::create([
            'market_id' => $platform->id,
            'message_type' => 'transactional',
            'primary_profile_id' => $profile->id,
            'fallback_to_sms' => true,
            'enabled' => true,
        ]);

        Http::fake();

        $result = app(MessagingDispatcher::class)->dispatch(
            MessageRecipient::fromPhone('0748612016', $platform->id),
            'Blocked',
            'whatsapp',
            ['message_type' => 'transactional']
        );

        $this->assertFalse($result->success);
        $this->assertSame('kill_switch_enabled', $result->errorCode);
        $this->assertSame('rejected', WhatsAppMessage::firstOrFail()->status);
        Http::assertNothingSent();
    }

    public function test_suppression_blocks_send_before_meta_http_call(): void
    {
        $platform = Platform::factory()->create();
        $profile = $this->createProfile($platform);
        WhatsAppRoutingRule::create([
            'market_id' => $platform->id,
            'message_type' => 'transactional',
            'primary_profile_id' => $profile->id,
            'fallback_to_sms' => true,
            'enabled' => true,
        ]);
        MessagingSuppression::create([
            'platform_id' => $platform->id,
            'phone_e164' => '254748612016',
            'channel' => 'whatsapp',
            'reason' => 'manual',
            'opted_out_at' => now(),
        ]);

        Http::fake();

        $result = app(MessagingDispatcher::class)->dispatch(
            MessageRecipient::fromPhone('0748612016', $platform->id),
            'Suppressed',
            'whatsapp',
            ['message_type' => 'transactional']
        );

        $this->assertFalse($result->success);
        $this->assertSame('suppressed', $result->status);
        $this->assertSame('suppressed', WhatsAppMessage::firstOrFail()->status);
        Http::assertNothingSent();
    }

    public function test_idempotency_key_returns_existing_message_without_second_meta_call(): void
    {
        $platform = Platform::factory()->create();
        $profile = $this->createProfile($platform);
        WhatsAppRoutingRule::create([
            'market_id' => $platform->id,
            'message_type' => 'transactional',
            'primary_profile_id' => $profile->id,
            'fallback_to_sms' => true,
            'enabled' => true,
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.dedupe']],
            ], 200),
        ]);

        $dispatcher = app(MessagingDispatcher::class);
        $recipient = MessageRecipient::fromPhone('0748612016', $platform->id);
        $context = [
            'message_type' => 'transactional',
            'idempotency_key' => 'stable-key',
        ];

        $first = $dispatcher->dispatch($recipient, 'Once', 'whatsapp', $context);
        $second = $dispatcher->dispatch($recipient, 'Once', 'whatsapp', $context);

        $this->assertTrue($first->success);
        $this->assertTrue($second->success);
        $this->assertSame($first->whatsAppMessage->id, $second->whatsAppMessage->id);
        $this->assertSame(1, WhatsAppMessage::count());
        Http::assertSentCount(1);
    }

    public function test_meta_sandbox_send_can_run_when_environment_is_configured(): void
    {
        if (!env('WHATSAPP_META_TEST_ACCESS_TOKEN') || !env('WHATSAPP_META_TEST_PHONE_NUMBER_ID') || !env('WHATSAPP_META_TEST_RECIPIENT')) {
            $this->markTestSkipped('Meta sandbox credentials are not configured.');
        }

        $platform = Platform::factory()->create();
        $profile = $this->createProfile($platform, [
            'meta_access_token' => env('WHATSAPP_META_TEST_ACCESS_TOKEN'),
            'meta_phone_number_id' => env('WHATSAPP_META_TEST_PHONE_NUMBER_ID'),
            'meta_api_version' => env('WHATSAPP_META_TEST_API_VERSION', config('services.whatsapp.meta_default_api_version')),
        ]);
        WhatsAppRoutingRule::create([
            'market_id' => $platform->id,
            'message_type' => 'transactional',
            'primary_profile_id' => $profile->id,
            'fallback_to_sms' => true,
            'enabled' => true,
        ]);

        $result = app(MessagingDispatcher::class)->dispatch(
            MessageRecipient::fromPhone(env('WHATSAPP_META_TEST_RECIPIENT'), $platform->id),
            'Exotic CRM Meta sandbox test',
            'whatsapp',
            ['message_type' => 'transactional']
        );

        $this->assertTrue($result->success, $result->errorMessage ?? 'Meta send failed.');
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
}
