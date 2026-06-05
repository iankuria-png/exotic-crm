<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyPaymentCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_payment_status_normalizes_legacy_success_rows(): void
    {
        $platform = $this->createPlatform('Kenya');

        $payment = Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '254700000001',
            'amount' => 1400,
            'currency' => 'KES',
            'transaction_uuid' => 'txn-legacy-001',
            'transaction_reference' => 'TXN-LEGACY-001',
            'status' => 'success',
        ]);

        $response = $this->postJson('/api/check-payment-status', [
            'payment_id' => $payment->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('payment_status', 'completed')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.transaction_uuid', 'txn-legacy-001');
    }

    public function test_success_page_route_is_not_swallowed_by_spa_catch_all(): void
    {
        $platform = $this->createPlatform('Uganda');

        Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '256700000001',
            'amount' => 2200,
            'currency' => 'UGX',
            'transaction_uuid' => 'txn-browser-001',
            'reference_number' => 'REF-BROWSER-001',
            'status' => 'completed',
        ]);

        $this->get('/success/txn-browser-001')
            ->assertOk()
            ->assertSee('Payment Successful!');
    }

    public function test_payment_status_exposes_failure_and_deal_context_for_crm_polling(): void
    {
        $platform = $this->createPlatform('Kenya');
        $product = Product::factory()->create(['platform_id' => $platform->id]);
        $client = Client::factory()->create(['platform_id' => $platform->id]);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'status' => 'awaiting_payment',
        ]);

        Payment::query()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $client->id,
            'deal_id' => $deal->id,
            'phone' => '+254700000001',
            'amount' => 1400,
            'currency' => 'KES',
            'transaction_uuid' => 'txn-crm-status-001',
            'transaction_reference' => 'CRM-SUB-STATUS',
            'status' => 'failed',
            'purpose' => 'subscription',
            'provider_key' => 'kopokopo',
            'provider_environment' => 'sandbox',
            'failure_reason' => 'Customer declined the STK prompt.',
            'payment_data' => [
                'provisioning_status' => 'suppressed_sandbox',
                'sandbox_suppressed' => true,
            ],
        ]);

        $this->getJson('/api/payment-status/txn-crm-status-001')
            ->assertOk()
            ->assertJsonPath('payment_status', 'failed')
            ->assertJsonPath('data.failure_reason', 'Customer declined the STK prompt.')
            ->assertJsonPath('data.deal_id', $deal->id)
            ->assertJsonPath('data.deal_status', 'awaiting_payment')
            ->assertJsonPath('data.purpose', 'subscription')
            ->assertJsonPath('data.provider_key', 'kopokopo')
            ->assertJsonPath('data.provider_environment', 'sandbox')
            ->assertJsonPath('data.provisioning_status', 'suppressed_sandbox')
            ->assertJsonPath('data.sandbox_suppressed', true);
    }

    private function createPlatform(string $name): Platform
    {
        return Platform::query()->create([
            'name' => $name,
            'domain' => Str::slug($name) . '-' . Str::random(6) . '.test',
            'country' => $name,
            'is_active' => true,
            'phone_prefix' => $name === 'Uganda' ? '256' : '254',
            'currency_code' => $name === 'Uganda' ? 'UGX' : 'KES',
            'wp_api_url' => 'https://example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }
}
