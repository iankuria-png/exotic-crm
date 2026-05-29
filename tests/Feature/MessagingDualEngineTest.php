<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppMessageAttempt;
use App\Models\WhatsAppProviderProfile;
use App\Models\WhatsAppRoutingRule;
use App\Models\WhatsAppSender;
use App\Services\Messaging\MessageRecipient;
use App\Services\Messaging\MessagingDispatcher;
use App\Services\Messaging\Sidecar\HmacSigner;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessagingDualEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_gateway_cascades_meta_to_baileys_and_records_attempts(): void
    {
        User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Config::set('services.whatsapp.sidecar_hmac_secret', 'sidecar-secret');
        Config::set('services.whatsapp.sidecar_url', 'https://sidecar.example.test');

        $platform = Platform::factory()->create();
        $meta = $this->profile($platform, 'meta_cloud_api', ['profile_name' => 'Meta Primary']);
        $baileys = $this->profile($platform, 'baileys', ['profile_name' => 'Baileys Fallback']);
        $sender = WhatsAppSender::create([
            'provider_profile_id' => $baileys->id,
            'phone_e164' => '254700000001',
            'connection_status' => WhatsAppSender::STATUS_CONNECTED,
            'daily_limit' => 20,
            'daily_sent_count' => 0,
        ]);

        WhatsAppRoutingRule::create([
            'market_id' => $platform->id,
            'message_type' => 'transactional',
            'primary_profile_id' => $meta->id,
            'fallback_profile_id' => $baileys->id,
            'fallback_to_sms' => true,
            'enabled' => true,
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['code' => 'meta_down', 'message' => 'Meta unavailable']], 500),
            'sidecar.example.test/messages' => Http::response(['attemptUuid' => 'sidecar-accepted'], 202),
        ]);

        $result = app(MessagingDispatcher::class)->dispatch(
            MessageRecipient::fromPhone('0748612016', $platform->id),
            'Dual engine test',
            'whatsapp_with_sms_fallback',
            ['message_type' => 'transactional', 'idempotency_key' => 'dual-engine-1']
        );

        $this->assertTrue($result->success);
        $this->assertFalse($result->fallbackAttempted);

        $message = WhatsAppMessage::firstOrFail();
        $this->assertSame('baileys', $message->engine);
        $this->assertSame($baileys->id, $message->provider_profile_id);
        $this->assertSame($sender->id, $message->sender_id);
        $this->assertNull($message->provider_message_id);
        $this->assertSame(0, $message->cost_micros);

        $this->assertSame(2, WhatsAppMessageAttempt::count());
        $this->assertDatabaseHas('whatsapp_message_attempts', [
            'whatsapp_message_id' => $message->id,
            'attempt_number' => 1,
            'engine' => 'meta_cloud_api',
            'status' => WhatsAppMessageAttempt::STATUS_FAILED,
        ]);
        $this->assertDatabaseHas('whatsapp_message_attempts', [
            'whatsapp_message_id' => $message->id,
            'attempt_number' => 2,
            'engine' => 'baileys',
            'sender_id' => $sender->id,
            'status' => WhatsAppMessageAttempt::STATUS_ACCEPTED,
        ]);
    }

    public function test_sms_fallback_only_runs_when_routing_allows_it(): void
    {
        User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $platform = Platform::factory()->create();
        WhatsAppRoutingRule::create([
            'market_id' => $platform->id,
            'message_type' => 'transactional',
            'primary_profile_id' => null,
            'fallback_profile_id' => null,
            'fallback_to_sms' => true,
            'enabled' => true,
        ]);

        app()->instance(NotificationService::class, new class extends NotificationService {
            public int $sends = 0;

            public function sendSms(?string $phone, string $message, array $context = []): array
            {
                $this->sends++;

                return [
                    'success' => true,
                    'status' => 'sent',
                    'provider' => 'fake',
                    'phone' => $phone,
                ];
            }
        });

        $dispatcher = app(MessagingDispatcher::class);
        $result = $dispatcher->dispatch(
            MessageRecipient::fromPhone('0748612016', $platform->id),
            'Fallback body',
            'whatsapp_with_sms_fallback',
            ['message_type' => 'transactional']
        );

        $this->assertTrue($result->success);
        $this->assertSame('sms', $result->channel);
        $this->assertTrue($result->fallbackAttempted);
        $this->assertSame('no_route', $result->errorCode);
    }

    public function test_retired_sender_releases_active_phone_uniqueness(): void
    {
        $platform = Platform::factory()->create();
        $profile = $this->profile($platform, 'baileys');

        $first = WhatsAppSender::create([
            'provider_profile_id' => $profile->id,
            'phone_e164' => '254700000001',
            'connection_status' => WhatsAppSender::STATUS_CONNECTED,
        ]);
        $first->forceFill([
            'connection_status' => WhatsAppSender::STATUS_RETIRED,
            'retired_at' => now(),
            'retired_reason' => 'replacement',
        ])->save();

        $second = WhatsAppSender::create([
            'provider_profile_id' => $profile->id,
            'phone_e164' => '254700000001',
            'connection_status' => WhatsAppSender::STATUS_PAIRING,
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseHas('whatsapp_senders', [
            'id' => $second->id,
            'active_phone_marker' => '254700000001',
        ]);
    }

    public function test_auth_blob_fetch_requires_one_shot_restore_token(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);
        Config::set('services.whatsapp.sidecar_laravel_hmac_secret', 'laravel-webhook-secret');

        $platform = Platform::factory()->create();
        $profile = $this->profile($platform, 'baileys');
        $sender = WhatsAppSender::create([
            'provider_profile_id' => $profile->id,
            'phone_e164' => '254700000001',
            'connection_status' => WhatsAppSender::STATUS_CONNECTED,
            'auth_state_encrypted' => json_encode(['creds' => ['noiseKey' => 'secret']]),
        ]);

        $restore = $this->signedPost('/api/crm/messaging/sidecar/restore-sessions', []);
        $restore->assertOk();
        $token = $restore->json('senders.0.restore_token');

        $first = $this->signedGet("/api/crm/messaging/sidecar/senders/{$sender->id}/auth-blob", [
            'X-Restore-Token' => $token,
        ]);
        $first->assertOk()
            ->assertJsonPath('sender_id', $sender->id)
            ->assertJsonPath('auth_state', json_encode(['creds' => ['noiseKey' => 'secret']]));

        $second = $this->signedGet("/api/crm/messaging/sidecar/senders/{$sender->id}/auth-blob", [
            'X-Restore-Token' => $token,
        ]);
        $second->assertForbidden();
    }

    private function profile(Platform $platform, string $engine, array $overrides = []): WhatsAppProviderProfile
    {
        return WhatsAppProviderProfile::create(array_merge([
            'market_id' => $platform->id,
            'engine' => $engine,
            'profile_name' => ucfirst($engine) . ' Profile ' . uniqid(),
            'environment' => 'sandbox',
            'kill_switch_enabled' => false,
            'meta_phone_number_id' => $engine === 'meta_cloud_api' ? '123456789' : null,
            'meta_business_account_id' => $engine === 'meta_cloud_api' ? '987654321' : null,
            'meta_access_token' => $engine === 'meta_cloud_api' ? 'test-token' : null,
            'meta_webhook_verify_token' => $engine === 'meta_cloud_api' ? 'verify-token' : null,
            'meta_app_secret' => $engine === 'meta_cloud_api' ? 'app-secret' : null,
            'meta_api_version' => 'v25.0',
            'active' => true,
        ], $overrides));
    }

    private function signedPost(string $uri, array $payload)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return $this
            ->withHeaders(['X-Signature' => app(HmacSigner::class)->sign((string) $body, 'laravel-webhook-secret')])
            ->postJson($uri, $payload);
    }

    private function signedGet(string $uri, array $headers = [])
    {
        $server = ['HTTP_X_SIGNATURE' => app(HmacSigner::class)->sign('', 'laravel-webhook-secret')];
        foreach ($headers as $key => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
        }

        return $this->call('GET', $uri, [], [], [], $server, '');
    }
}
