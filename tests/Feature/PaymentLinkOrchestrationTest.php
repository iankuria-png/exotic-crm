<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Support\CrmAuditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentLinkOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_queue_send_link_records_attempt_and_audit_through_shared_service(): void
    {
        $platform = $this->createPlatform();
        $product = $this->createProduct($platform);
        $user = $this->createUser($platform);
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'status' => 'initiated',
            'phone' => '0711000001',
            'amount' => 2500,
            'currency' => 'KES',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/payments/{$payment->id}/send-payment-link", [
            'channel' => 'sms',
            'reason' => 'Recover pending payment with self-serve link',
        ]);

        $response->assertOk()
            ->assertJsonPath('payment.id', $payment->id);

        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'attempt_type' => 'send_payment_link',
            'status' => 'disabled',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'action' => CrmAuditAction::PAYMENT_SEND_LINK,
            'entity_type' => 'payment',
            'entity_id' => $payment->id,
        ]);
    }

    public function test_deal_link_activation_records_attempt_and_audit_through_shared_service(): void
    {
        $platform = $this->createPlatform();
        $product = $this->createProduct($platform);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'phone_normalized' => '254711000002',
            'profile_status' => 'private',
        ]);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'plan_type' => 'premium',
            'amount' => 3200,
            'currency' => 'KES',
            'duration' => 'monthly',
            'status' => 'pending',
            'activated_at' => null,
            'expires_at' => null,
            'payment_id' => null,
            'payment_reference' => null,
        ]);
        $user = $this->createUser($platform);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/deals/{$deal->id}/activate", [
            'reason' => 'Send payment link instead of STK',
            'payment_method' => 'link',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('deal.id', $deal->id);

        $payment = Payment::query()->where('deal_id', $deal->id)->latest('id')->firstOrFail();

        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'attempt_type' => 'send_payment_link',
            'status' => 'disabled',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'action' => CrmAuditAction::PAYMENT_SEND_LINK,
            'entity_type' => 'payment',
            'entity_id' => $payment->id,
        ]);
    }

    private function createPlatform(): Platform
    {
        return Platform::factory()->create([
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://example.test/wp-json/exotic-crm-sync/v1',
            'payment_link_providers' => [
                'active_provider' => 'site_pay_page',
                'providers' => [
                    'site_pay_page' => [
                        'url' => 'https://checkout.example.test/pay',
                    ],
                ],
            ],
        ]);
    }

    private function createProduct(Platform $platform): Product
    {
        return Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Premium Plan',
            'display_name' => 'Premium Plan',
            'slug' => 'premium-plan',
            'tier' => 'premium',
            'weekly_price' => 900,
            'biweekly_price' => 1800,
            'monthly_price' => 3200,
            'currency' => 'KES',
            'is_active' => true,
        ]);
    }

    private function createUser(Platform $platform): User
    {
        return User::query()->create([
            'name' => 'Sales ' . Str::random(6),
            'email' => Str::random(8) . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [$platform->id],
        ]);
    }
}
