<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentManualCloseTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_converted_close_links_matching_completed_payment(): void
    {
        $platform = $this->createPlatform();
        $product = $this->createProduct($platform);
        $admin = $this->createAdminUser();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'phone_normalized' => '254700111222',
        ]);
        $convertedPayment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $client->id,
            'phone' => '254700111222',
            'status' => 'completed',
            'reconciliation_state' => 'resolved',
            'created_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(4),
        ]);
        $failedPayment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => null,
            'phone' => '254700111222',
            'status' => 'failed',
            'reconciliation_state' => 'open',
            'failure_reason' => 'Customer retried and paid successfully',
            'created_at' => now()->subMinutes(10),
            'completed_at' => null,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/crm/payments/{$failedPayment->id}/manual-close", [
            'reason_code' => 'customer_converted',
            'reason_note' => 'Customer paid on the second attempt.',
        ]);

        $response->assertOk()
            ->assertJsonPath('payment.id', $failedPayment->id)
            ->assertJsonPath('payment.reconciliation_state', 'resolved')
            ->assertJsonPath('payment.resolution_code', 'customer_converted');

        $failedPayment->refresh();
        $this->assertSame($client->id, (int) $failedPayment->client_id);
        $this->assertSame('manual', $failedPayment->match_confidence);
        $this->assertSame($admin->id, (int) $failedPayment->confirmed_by);
        $this->assertSame($convertedPayment->id, data_get($failedPayment->resolution_meta_json, 'manual_close.converted_payment.payment_id'));
        $this->assertSame($convertedPayment->id, data_get($failedPayment->raw_payload, 'manual_close.converted_payment.payment_id'));
        $this->assertTrue(data_get($failedPayment->resolution_meta_json, 'manual_close.converted_payment_linked'));
    }

    public function test_systems_down_close_uses_payment_specific_reason(): void
    {
        $platform = $this->createPlatform();
        $product = $this->createProduct($platform);
        $admin = $this->createAdminUser();
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'status' => 'failed',
            'reconciliation_state' => 'open',
            'failure_reason' => 'Provider timeout',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/crm/payments/{$payment->id}/manual-close", [
            'reason_code' => 'systems_down',
            'reason_note' => 'Provider timeout during outage.',
        ]);

        $response->assertOk()
            ->assertJsonPath('payment.reconciliation_state', 'resolved')
            ->assertJsonPath('payment.resolution_code', 'systems_down');

        $payment->refresh();
        $this->assertSame('Systems Were Down', data_get($payment->resolution_meta_json, 'manual_close.reason_label'));
    }

    private function createPlatform(): Platform
    {
        return Platform::factory()->create([
            'name' => 'Kenya Market',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'timezone' => 'Africa/Nairobi',
        ]);
    }

    private function createProduct(Platform $platform): Product
    {
        return Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'VIP',
            'display_name' => 'VIP',
            'slug' => 'vip',
            'tier' => 'vip',
            'weekly_price' => 1000,
            'biweekly_price' => 2000,
            'monthly_price' => 4000,
            'currency' => 'KES',
            'is_active' => true,
        ]);
    }

    private function createAdminUser(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => [],
            'email' => 'payment-close-admin-' . uniqid('', true) . '@example.test',
        ]);
    }
}
