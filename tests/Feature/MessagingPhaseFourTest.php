<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientCredentialDispatch;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Template;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppProviderProfile;
use App\Models\WhatsAppRoutingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessagingPhaseFourTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_send_can_dispatch_whatsapp_and_log_channel_specific_note_and_timeline(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'phone_normalized' => '254748612016',
        ]);
        $this->routeWhatsApp($platform, 'conversation');
        Sanctum::actingAs($admin);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.conversation']]], 200)]);

        $response = $this->postJson("/api/crm/conversations/clients/{$client->id}/send", [
            'channel' => 'whatsapp',
            'message' => 'Hello on WhatsApp',
        ]);

        $response->assertCreated()
            ->assertJsonPath('note.note_type', 'whatsapp')
            ->assertJsonPath('delivery.channel', 'whatsapp')
            ->assertJsonPath('delivery.success', true);

        $message = WhatsAppMessage::firstOrFail();
        $this->assertSame($client->id, $message->client_id);
        $this->assertSame('conversation', $message->providerProfile->routingRules()->first()?->message_type);
        $this->assertDatabaseHas('timeline_events', [
            'platform_id' => $platform->id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'conversation_whatsapp_sent',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'actor_id' => $admin->id,
            'action' => 'conversation_whatsapp_sent',
            'entity_type' => 'client',
            'entity_id' => $client->id,
        ]);
    }

    public function test_manual_renewal_reminder_can_dispatch_whatsapp(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'phone_normalized' => '254748612016',
        ]);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
        ]);
        $template = Template::create([
            'platform_id' => $platform->id,
            'title' => 'Renewal WhatsApp',
            'category' => 'renewal',
            'channel' => 'whatsapp',
            'body' => 'Renew your profile today.',
            'status' => 'active',
        ]);
        $this->routeWhatsApp($platform, 'renewal');
        Sanctum::actingAs($admin);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.renewal']]], 200)]);

        $response = $this->postJson('/api/crm/renewals/remind', [
            'deal_id' => $deal->id,
            'template_id' => $template->id,
            'channel' => 'whatsapp',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('provider', 'whatsapp');

        $this->assertDatabaseHas('timeline_events', [
            'platform_id' => $platform->id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'renewal_whatsapp_sent',
        ]);
        $this->assertSame($deal->id, WhatsAppMessage::firstOrFail()->deal_id);
    }

    public function test_payment_link_send_accepts_whatsapp_and_records_payment_context(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $platform = Platform::factory()->create([
            'domain' => 'kenya.example.test',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'phone_normalized' => '254748612016',
        ]);
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'phone' => '254748612016',
            'status' => 'failed',
        ]);
        $this->routeWhatsApp($platform, 'payment_link');
        Sanctum::actingAs($admin);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.payment']]], 200)]);

        $response = $this->postJson("/api/crm/payments/{$payment->id}/send-payment-link", [
            'channel' => 'whatsapp',
            'reason' => 'Send payment link by WhatsApp',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Payment link sent by WhatsApp.');

        $message = WhatsAppMessage::firstOrFail();
        $this->assertSame($payment->id, $message->payment_id);
        $this->assertSame($client->id, $message->client_id);
        $this->assertSame('sent', $message->status);
    }

    public function test_credential_delivery_accepts_whatsapp_channel(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $platform = Platform::factory()->create([
            'domain' => 'kenya.example.test',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'phone_normalized' => '254748612016',
            'email' => 'client@example.test',
        ]);
        $this->routeWhatsApp($platform, 'credential');
        Sanctum::actingAs($admin);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.credential']]], 200)]);

        $response = $this->postJson("/api/crm/clients/{$client->id}/credentials/dispatch", [
            'method' => 'setup_link',
            'channel' => 'whatsapp',
            'timing' => 'send_now',
            'recipient_phone' => '254748612016',
            'reason' => 'Send credentials by WhatsApp',
        ]);

        $response->assertCreated()
            ->assertJsonPath('dispatch.channel', 'whatsapp')
            ->assertJsonPath('dispatch.status', 'sent');

        $dispatch = ClientCredentialDispatch::firstOrFail();
        $this->assertTrue((bool) data_get($dispatch->provider_results, 'whatsapp.success'));
        $this->assertSame($client->id, WhatsAppMessage::firstOrFail()->client_id);
    }

    private function routeWhatsApp(Platform $platform, string $messageType): WhatsAppProviderProfile
    {
        $profile = WhatsAppProviderProfile::create([
            'market_id' => $platform->id,
            'engine' => 'meta_cloud_api',
            'profile_name' => 'Meta Sandbox ' . $messageType,
            'environment' => 'sandbox',
            'kill_switch_enabled' => false,
            'meta_phone_number_id' => '123456789',
            'meta_business_account_id' => '987654321',
            'meta_access_token' => 'test-token',
            'meta_webhook_verify_token' => 'verify-token',
            'meta_app_secret' => 'app-secret',
            'meta_api_version' => 'v25.0',
            'active' => true,
        ]);

        WhatsAppRoutingRule::create([
            'market_id' => $platform->id,
            'message_type' => $messageType,
            'primary_profile_id' => $profile->id,
            'fallback_to_sms' => true,
            'enabled' => true,
        ]);

        return $profile;
    }
}
