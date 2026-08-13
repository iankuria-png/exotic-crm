<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Services\BillingGatewayService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegacyMutationRouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @dataProvider staffMutationRoutes
     */
    public function test_legacy_staff_mutation_routes_reject_anonymous_callers(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertStatus(401);
    }

    /**
     * @dataProvider staffMutationRoutes
     */
    public function test_legacy_staff_mutation_routes_reject_non_admin_callers(string $method, string $uri): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
        ]));

        $this->json($method, $uri)->assertStatus(403);
    }

    public function test_admin_can_reach_product_write_controllers(): void
    {
        Sanctum::actingAs($this->adminUser());

        $this->postJson('/api/products', [])->assertStatus(422);

        $product = Product::factory()->create();

        $this->putJson("/api/products/{$product->id}", [])->assertStatus(422);
        $this->deleteJson("/api/products/{$product->id}")->assertOk();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_public_catalog_reads_remain_public(): void
    {
        Platform::factory()->create();
        Product::factory()->create();

        $this->getJson('/api/platforms')->assertOk();
        $this->getJson('/api/products')->assertOk();
    }

    public function test_platform_writes_are_admin_only(): void
    {
        $platform = Platform::factory()->create();

        $this->postJson('/api/platforms', [])->assertStatus(401);
        $this->putJson("/api/platforms/{$platform->id}", [])->assertStatus(401);
        $this->deleteJson("/api/platforms/{$platform->id}")->assertStatus(401);

        Sanctum::actingAs(User::factory()->create([
            'role' => 'sub_admin',
            'status' => 'active',
        ]));

        $this->postJson('/api/platforms', [])->assertStatus(403);
        $this->putJson("/api/platforms/{$platform->id}", [])->assertStatus(403);
        $this->deleteJson("/api/platforms/{$platform->id}")->assertStatus(403);

        Sanctum::actingAs($this->adminUser());

        $this->postJson('/api/platforms', [])->assertStatus(422);
        $this->putJson("/api/platforms/{$platform->id}", ['name' => 'Renamed Market'])->assertOk();
        $this->deleteJson("/api/platforms/{$platform->id}")->assertOk();
    }

    /**
     * @dataProvider debugInfoRoutes
     */
    public function test_payment_debug_info_routes_reject_anonymous_callers(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertStatus(401);
    }

    /**
     * @dataProvider debugInfoRoutes
     */
    public function test_payment_debug_info_routes_reject_non_admin_callers(string $method, string $uri): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
        ]));

        $this->json($method, $uri)->assertStatus(403);
    }

    public function test_admin_can_reach_debug_info_routes_without_public_exposure(): void
    {
        Sanctum::actingAs($this->adminUser());

        $this->getJson('/api/debug-kopokopo')->assertOk();
        $this->getJson('/api/webhook-info')->assertOk();
        $this->postJson('/api/test-webhook', ['probe' => true])->assertOk();

        config(['app.debug' => false]);

        $this->postJson('/api/subscribe-webhooks')->assertStatus(403);
    }

    public function test_admin_webhook_simulator_generates_payload_without_mutating_payment(): void
    {
        config(['app.debug' => true]);

        Sanctum::actingAs($this->adminUser());

        $payment = Payment::factory()->create(['status' => 'pending']);

        $this->postJson('/api/simulate-webhook', ['payment_id' => $payment->id])
            ->assertOk()
            ->assertJsonPath('message', 'Webhook payload generated; no payment mutation was performed.');

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_django_payment_update_requires_callback_id_and_valid_signature(): void
    {
        config(['services.django.callback_secret' => 'test-django-callback-secret']);

        $payment = Payment::factory()->create(['status' => 'pending']);
        $payload = $this->djangoCallbackPayload($payment);

        $this->postJson('/api/payment/update', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'missing_callback_id');

        $this->call('POST', '/api/payment/update', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_EXOTIC_CALLBACK_ID' => 'callback-without-signature',
        ], json_encode($payload))
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'invalid_signature');

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_mixed_payment_callback_requires_django_signature_or_verified_kopokopo_signature(): void
    {
        config(['services.django.callback_secret' => 'test-django-callback-secret']);

        $payment = Payment::factory()->create(['status' => 'pending']);
        $payload = $this->djangoCallbackPayload($payment);

        $this->postJson('/api/payment-callback', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'missing_callback_id');

        $this->postSignedDjangoCallback($payload, 'legacy-payment-callback-1', '/api/payment-callback')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('failed', $payment->fresh()->status);

        $this->mock(BillingGatewayService::class, function ($mock): void {
            $mock->shouldReceive('handleMpesaCallback')
                ->once()
                ->andThrow(new \RuntimeException('Invalid M-Pesa callback signature.'));
        });

        $this->call('POST', '/api/payment-callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_KOPOKOPO_SIGNATURE' => 'bogus-signature',
        ], json_encode(['metadata' => ['payment_id' => $payment->id]]))
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'webhook_verification_failed');
    }

    public function test_signed_django_payment_update_mutates_once_and_rejects_replay(): void
    {
        config(['services.django.callback_secret' => 'test-django-callback-secret']);

        $payment = Payment::factory()->create(['status' => 'pending']);
        $payload = $this->djangoCallbackPayload($payment);

        $this->postSignedDjangoCallback($payload, 'callback-replay-proof-1')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('failed', $payment->fresh()->status);

        $this->postSignedDjangoCallback($payload, 'callback-replay-proof-1')
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'duplicate_callback');
    }

    public function test_admin_can_reach_legacy_staff_payment_mutation_controllers(): void
    {
        Sanctum::actingAs($this->adminUser());

        $payment = Payment::factory()->create(['status' => 'completed']);

        $this->postJson('/api/manual-update', [])->assertStatus(422);

        $this->postJson('/api/manual-update', [
            'payment_id' => $payment->id,
            'status' => 'completed',
        ])->assertStatus(400);

        $this->postJson('/api/activate-profile', [])->assertStatus(422);
        $this->postJson('/api/deactivate-profile', [])->assertStatus(422);
        $this->postJson('/api/manual-stk-push', [])->assertStatus(422);

        config(['app.debug' => false]);

        $this->postJson('/api/clear-pending-payments', [])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Debug mode required');
    }

    public static function staffMutationRoutes(): array
    {
        return [
            'create product' => ['POST', '/api/products'],
            'update product' => ['PUT', '/api/products/1'],
            'delete product' => ['DELETE', '/api/products/1'],
            'manual payment update' => ['POST', '/api/manual-update'],
            'manual profile activation' => ['POST', '/api/activate-profile'],
            'manual profile deactivation' => ['POST', '/api/deactivate-profile'],
            'manual stk push' => ['POST', '/api/manual-stk-push'],
            'clear pending payments' => ['POST', '/api/clear-pending-payments'],
        ];
    }

    public static function debugInfoRoutes(): array
    {
        return [
            'debug kopokopo' => ['GET', '/api/debug-kopokopo'],
            'webhook info' => ['GET', '/api/webhook-info'],
            'test webhook' => ['POST', '/api/test-webhook'],
            'subscribe webhooks' => ['POST', '/api/subscribe-webhooks'],
            'simulate webhook' => ['POST', '/api/simulate-webhook'],
        ];
    }

    private function adminUser(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function djangoCallbackPayload(Payment $payment): array
    {
        return [
            'payment_id' => $payment->id,
            'status' => 'failed',
            'transaction_reference' => 'DJANGO-CALLBACK-FAILED',
            'resource' => [
                'payment_id' => $payment->id,
                'status' => 'Failed',
                'reference' => 'DJANGO-CALLBACK-FAILED',
            ],
            'metadata' => [
                'payment_id' => $payment->id,
            ],
            'rawData' => [
                'provider' => 'django_proxy',
            ],
        ];
    }

    private function postSignedDjangoCallback(array $payload, string $callbackId, string $uri = '/api/payment/update')
    {
        $body = json_encode($payload);
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, 'test-django-callback-secret');

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_EXOTIC_CALLBACK_ID' => $callbackId,
            'HTTP_X_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body);
    }
}
