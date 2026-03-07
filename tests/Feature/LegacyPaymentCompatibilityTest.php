<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Platform;
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
