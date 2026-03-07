<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionProvisioningConvergenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_deal_activation_route_uses_shared_provisioning_service_for_manual_payments(): void
    {
        $platform = $this->createPlatform();
        $product = $this->createProduct($platform, 'Basic Escort', 1500);
        $client = $this->createClient($platform, 9001);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'plan_type' => 'basic',
            'amount' => 1500,
            'currency' => 'KES',
            'duration' => 'monthly',
            'status' => 'pending',
            'activated_at' => null,
            'expires_at' => null,
            'payment_id' => null,
            'payment_reference' => null,
        ]);

        $this->fakeProvisioningApis($platform, $client);

        Sanctum::actingAs($this->createAdminUser());

        $response = $this->postJson("/api/crm/deals/{$deal->id}/activate", [
            'reason' => 'Activate after manual payment confirmation',
            'payment_method' => 'manual',
            'payment_reference' => 'MANUAL-REF-001',
        ]);

        $response->assertOk();

        $deal->refresh();
        $payment = Payment::query()->findOrFail($deal->payment_id);
        $client->refresh();

        $this->assertSame('active', $deal->status);
        $this->assertSame('MANUAL-REF-001', $deal->payment_reference);
        $this->assertSame('completed', $payment->status);
        $this->assertSame($deal->id, $payment->deal_id);
        $this->assertSame($client->id, $payment->client_id);
        $this->assertNotNull($payment->start_date);
        $this->assertNotNull($payment->end_date);
        $this->assertSame('publish', $client->profile_status);

        $this->assertDatabaseHas('timeline_events', [
            'platform_id' => $platform->id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'profile_activated',
        ]);
        $this->assertDatabaseHas('timeline_events', [
            'platform_id' => $platform->id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'deal_activated',
        ]);

        $this->assertProvisioningRequestsSent($platform, $client);
    }

    public function test_payment_queue_create_subscription_provisions_matched_completed_payment_through_wp_sync(): void
    {
        $platform = $this->createPlatform();
        $product = $this->createProduct($platform, 'Premium Escort', 2200);
        $client = $this->createClient($platform, 9002);
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $client->id,
            'phone' => $client->phone_normalized,
            'amount' => 2200,
            'currency' => 'KES',
            'transaction_uuid' => 'queue-payment-001',
            'transaction_reference' => 'QUEUE-REF-001',
            'status' => 'completed',
            'reconciliation_confidence' => 'high',
            'reconciliation_state' => 'resolved',
            'match_confidence' => 'auto_high',
            'duration' => 'monthly',
            'deal_id' => null,
            'start_date' => null,
            'end_date' => null,
        ]);

        $this->fakeProvisioningApis($platform, $client, [
            'premium' => true,
            'premium_expire' => now()->addDays(30)->timestamp,
        ]);

        Sanctum::actingAs($this->createAdminUser());

        $response = $this->postJson("/api/crm/payments/{$payment->id}/create-subscription", [
            'reason' => 'Create subscription from confirmed payment',
        ]);

        $response->assertCreated();

        $payment->refresh();
        $deal = Deal::query()->findOrFail($payment->deal_id);
        $client->refresh();

        $this->assertSame('active', $deal->status);
        $this->assertSame($payment->id, $deal->payment_id);
        $this->assertSame($client->id, $deal->client_id);
        $this->assertSame('publish', $client->profile_status);
        $this->assertNotNull($payment->start_date);
        $this->assertNotNull($payment->end_date);

        $this->assertDatabaseHas('timeline_events', [
            'platform_id' => $platform->id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'payment_received',
        ]);
        $this->assertDatabaseHas('timeline_events', [
            'platform_id' => $platform->id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'deal_activated',
        ]);

        $this->assertProvisioningRequestsSent($platform, $client);
    }

    public function test_manual_payment_status_update_activates_existing_awaiting_payment_deal(): void
    {
        $platform = $this->createPlatform();
        $product = $this->createProduct($platform, 'VIP Escort', 3200);
        $client = $this->createClient($platform, 9003);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'plan_type' => 'vip',
            'amount' => 3200,
            'currency' => 'KES',
            'duration' => 'monthly',
            'status' => 'awaiting_payment',
            'activated_at' => null,
            'expires_at' => null,
        ]);
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $client->id,
            'deal_id' => $deal->id,
            'phone' => $client->phone_normalized,
            'amount' => 3200,
            'currency' => 'KES',
            'transaction_uuid' => 'callback-payment-001',
            'transaction_reference' => 'CALLBACK-INIT-001',
            'status' => 'initiated',
            'duration' => 'monthly',
            'start_date' => null,
            'end_date' => null,
            'raw_payload' => [
                'source' => 'deal_payment_initiation',
                'method' => 'stk',
                'deal_id' => $deal->id,
            ],
        ]);
        $deal->forceFill([
            'payment_id' => $payment->id,
            'payment_reference' => $payment->transaction_reference,
        ])->save();

        $this->fakeProvisioningApis($platform, $client);

        $response = $this->postJson('/api/manual-update', [
            'payment_id' => $payment->id,
            'status' => 'completed',
            'transaction_reference' => 'CALLBACK-SUCCESS-001',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $payment->refresh();
        $deal->refresh();
        $client->refresh();

        $this->assertSame('completed', $payment->status);
        $this->assertSame('CALLBACK-SUCCESS-001', $payment->transaction_reference);
        $this->assertSame('active', $deal->status);
        $this->assertSame($payment->id, $deal->payment_id);
        $this->assertSame($payment->transaction_reference, $deal->payment_reference);
        $this->assertNotNull($payment->start_date);
        $this->assertNotNull($payment->end_date);
        $this->assertSame('publish', $client->profile_status);

        $this->assertDatabaseHas('timeline_events', [
            'platform_id' => $platform->id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'payment_received',
        ]);
        $this->assertDatabaseHas('timeline_events', [
            'platform_id' => $platform->id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'deal_activated',
        ]);

        $this->assertProvisioningRequestsSent($platform, $client);
    }

    private function createPlatform(): Platform
    {
        return Platform::factory()->create([
            'name' => 'Kenya Market',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function createProduct(Platform $platform, string $name, float $monthlyPrice): Product
    {
        $normalizedName = strtolower($name);
        $tier = str_contains($normalizedName, 'vip')
            ? 'vip'
            : (str_contains($normalizedName, 'premium') ? 'premium' : 'basic');

        return Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => $name,
            'display_name' => $name,
            'slug' => Str::slug($name),
            'tier' => $tier,
            'weekly_price' => round($monthlyPrice / 4, 2),
            'biweekly_price' => round($monthlyPrice / 2, 2),
            'monthly_price' => $monthlyPrice,
            'currency' => 'KES',
            'is_active' => true,
        ]);
    }

    private function createClient(Platform $platform, int $wpPostId): Client
    {
        return Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => $wpPostId,
            'wp_user_id' => $wpPostId + 5000,
            'phone_normalized' => '254700' . str_pad((string) $wpPostId, 6, '0', STR_PAD_LEFT),
            'profile_status' => 'private',
            'premium' => false,
            'featured' => false,
            'verified' => false,
        ]);
    }

    private function createAdminUser(): User
    {
        return User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin-' . Str::random(8) . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => null,
        ]);
    }

    private function fakeProvisioningApis(
        Platform $platform,
        Client $client,
        array $profileOverrides = []
    ): void {
        $baseUrl = rtrim((string) $platform->wp_api_url, '/');
        $profilePayload = array_merge([
            'wp_post_id' => (int) $client->wp_post_id,
            'wp_user_id' => (int) $client->wp_user_id,
            'name' => (string) $client->name,
            'phone' => (string) $client->phone_normalized,
            'email' => (string) $client->email,
            'city' => (string) $client->city,
            'post_status' => 'publish',
            'premium' => false,
            'featured' => false,
            'verified' => false,
            'main_image_url' => (string) ($client->main_image_url ?? ''),
            'premium_expire' => null,
            'featured_expire' => null,
            'escort_expire' => now()->addDays(30)->timestamp,
            'last_online' => null,
        ], $profileOverrides);

        $fakes = [
            "{$baseUrl}/clients/{$client->wp_post_id}/activate" => Http::response([
                'success' => true,
                'crm_deal_id' => null,
            ], 200),
            "{$baseUrl}/clients/{$client->wp_post_id}" => Http::response($profilePayload, 200),
        ];

        $fakes['*'] = Http::response([], 200);

        Http::fake($fakes);
    }

    private function assertProvisioningRequestsSent(Platform $platform, Client $client): void
    {
        $baseUrl = rtrim((string) $platform->wp_api_url, '/');

        Http::assertSent(fn ($request) => $request->url() === "{$baseUrl}/clients/{$client->wp_post_id}/activate");
        Http::assertSent(fn ($request) => $request->url() === "{$baseUrl}/clients/{$client->wp_post_id}");
    }
}
