<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\ManualPaymentBundle;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManualPaymentBundleVoidTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_void_committed_bundle(): void
    {
        [$platform, $admin, $bundle] = $this->createCommittedBundle();

        Http::fake([
            '*wp-json/exotic-crm-sync/v1/clients/*/deactivate' => Http::response(['ok' => true], 200),
            '*wp-json/exotic-crm-sync/v1/clients/*/sync' => Http::response(['ok' => true], 200),
            '*wp-json/exotic-crm-sync/v1/clients/*' => Http::response([
                'post_id' => 93000,
                'user_id' => 83000,
                'status' => 'private',
            ], 200),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/crm/manual-payment-bundles/{$bundle->id}/void", [
            'reason_code' => 'fraud_suspected',
            'notes' => 'Confirmed fraudulent transaction.',
        ]);

        $response->assertOk()
            ->assertJsonPath('bundle.status', 'voided')
            ->assertJsonPath('bundle.audit_state', 'voided');

        $bundle->refresh();
        $this->assertSame('voided', $bundle->status);
        $this->assertSame('voided', $bundle->audit_state);

        // All child payments should have resolution_code set
        foreach ($bundle->payments()->get() as $payment) {
            $this->assertNotNull($payment->resolution_code, "Payment #{$payment->id} should have resolution_code");
        }

        // All linked deals should be cancelled
        $activeDealCount = Deal::query()
            ->whereIn('payment_id', $bundle->payments()->pluck('id'))
            ->where('status', 'active')
            ->count();

        $this->assertSame(0, $activeDealCount, 'No deals should remain active after void.');
    }

    public function test_void_marks_clients_high_risk_for_fraud_reason(): void
    {
        [$platform, $admin, $bundle] = $this->createCommittedBundle();

        Http::fake([
            '*wp-json/exotic-crm-sync/v1/*' => Http::response(['ok' => true, 'post_id' => 93000, 'user_id' => 83000, 'status' => 'private'], 200),
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/crm/manual-payment-bundles/{$bundle->id}/void", [
            'reason_code' => 'fraud_suspected',
            'notes' => 'Fraud test.',
        ])->assertOk();

        $clientIds = $bundle->payments()->pluck('client_id')->unique()->filter();
        foreach ($clientIds as $clientId) {
            $this->assertDatabaseHas('clients', [
                'id' => $clientId,
                'is_high_risk' => true,
            ]);
        }
    }

    public function test_void_does_not_mark_high_risk_for_customer_request(): void
    {
        [$platform, $admin, $bundle] = $this->createCommittedBundle();

        Http::fake([
            '*wp-json/exotic-crm-sync/v1/*' => Http::response(['ok' => true, 'post_id' => 93000, 'user_id' => 83000, 'status' => 'private'], 200),
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/crm/manual-payment-bundles/{$bundle->id}/void", [
            'reason_code' => 'customer_request',
            'notes' => 'Customer asked for reversal.',
        ])->assertOk();

        $clientIds = $bundle->payments()->pluck('client_id')->unique()->filter();
        foreach ($clientIds as $clientId) {
            $client = Client::query()->find($clientId);
            $this->assertFalse((bool) $client->is_high_risk, "Client #{$clientId} should NOT be high risk.");
        }
    }

    public function test_void_refuses_when_child_deal_has_been_extended(): void
    {
        [$platform, $admin, $bundle] = $this->createCommittedBundle();

        // Simulate extension: move expires_at far forward on one child deal
        $payment = $bundle->payments()->first();
        $deal = Deal::query()->find($payment->deal_id);
        $deal->forceFill([
            'expires_at' => now()->addDays(365),
        ])->save();

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/crm/manual-payment-bundles/{$bundle->id}/void", [
            'reason_code' => 'fraud_suspected',
            'notes' => 'Divergence test.',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('divergence');

        // Bundle should remain committed
        $bundle->refresh();
        $this->assertSame('committed', $bundle->status);
    }

    public function test_void_refuses_when_child_deal_is_relinked_to_different_payment(): void
    {
        [$platform, $admin, $bundle] = $this->createCommittedBundle();

        $payment = $bundle->payments()->first();
        $deal = Deal::query()->find($payment->deal_id);
        $product = Product::query()->find($deal->product_id);

        // Create a different payment and relink the deal
        $otherPayment = Payment::query()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'deal_id' => $deal->id,
            'client_id' => $deal->client_id,
            'amount' => 2500,
            'currency' => 'KES',
            'status' => 'completed',
            'phone' => '254700009333',
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => 'OTHERTXN001',
            'source' => 'test',
            'provider_key' => 'manual_confirmation',
            'provider_environment' => 'production',
        ]);

        $deal->forceFill([
            'payment_id' => $otherPayment->id,
        ])->save();

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/crm/manual-payment-bundles/{$bundle->id}/void", [
            'reason_code' => 'duplicate_entry',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('divergence');
    }

    public function test_non_admin_cannot_void_bundle(): void
    {
        [$platform, , $bundle] = $this->createCommittedBundle();

        $salesUser = User::query()->create([
            'name' => 'Sales Void Test',
            'email' => 'sales-void-' . Str::random(6) . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'sales',
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);

        Sanctum::actingAs($salesUser);

        $this->postJson("/api/crm/manual-payment-bundles/{$bundle->id}/void", [
            'reason_code' => 'fraud_suspected',
        ])->assertForbidden();
    }

    public function test_void_refuses_already_voided_bundle(): void
    {
        [$platform, $admin, $bundle] = $this->createCommittedBundle();

        $bundle->forceFill(['status' => 'voided', 'audit_state' => 'voided'])->save();

        Sanctum::actingAs($admin);

        $this->postJson("/api/crm/manual-payment-bundles/{$bundle->id}/void", [
            'reason_code' => 'fraud_suspected',
        ])->assertUnprocessable();
    }

    public function test_show_returns_bundle_detail_with_divergence(): void
    {
        [$platform, $admin, $bundle] = $this->createCommittedBundle();

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/crm/manual-payment-bundles/{$bundle->id}");

        $response->assertOk()
            ->assertJsonPath('bundle.id', $bundle->id)
            ->assertJsonPath('bundle.status', 'committed')
            ->assertJsonStructure([
                'bundle' => [
                    'id', 'platform_id', 'reference_root', 'total_amount',
                    'allocated_amount', 'status', 'audit_state', 'payments',
                ],
                'divergence',
            ]);
    }

    public function test_void_refuses_when_bundle_is_committing(): void
    {
        [$platform, $admin, $bundle] = $this->createCommittedBundle();

        $bundle->forceFill(['status' => 'committing'])->save();

        Sanctum::actingAs($admin);

        $this->postJson("/api/crm/manual-payment-bundles/{$bundle->id}/void", [
            'reason_code' => 'fraud_suspected',
        ])->assertUnprocessable();
    }

    /**
     * @return array{0: Platform, 1: User, 2: ManualPaymentBundle}
     */
    private function createCommittedBundle(): array
    {
        $platform = $this->createPlatform();

        $admin = User::query()->create([
            'name' => 'Admin Void Tester',
            'email' => 'admin-void-' . Str::random(6) . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);

        $product = Product::query()->create([
            'platform_id' => $platform->id,
            'name' => 'VoidTest Product ' . Str::random(4),
            'display_name' => 'Void Test Product',
            'slug' => 'void-test-' . Str::random(6),
            'tier' => 'custom',
            'weekly_price' => 1000,
            'biweekly_price' => 1800,
            'monthly_price' => 2500,
            'currency' => 'KES',
        ]);

        $clientA = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 93000,
            'wp_user_id' => 83000,
            'phone_normalized' => '254700009111',
            'is_high_risk' => false,
        ]);

        $clientB = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 93001,
            'wp_user_id' => 83001,
            'phone_normalized' => '254700009222',
            'is_high_risk' => false,
        ]);

        $referenceRoot = 'VOID' . strtoupper(Str::random(4));

        $bundle = ManualPaymentBundle::query()->create([
            'platform_id' => $platform->id,
            'reference_root' => $referenceRoot,
            'total_amount' => 5000,
            'allocated_amount' => 5000,
            'unallocated_amount' => 0,
            'currency' => 'KES',
            'reason' => 'Test void bundle',
            'status' => ManualPaymentBundle::STATUS_COMMITTED,
            'audit_state' => ManualPaymentBundle::AUDIT_PENDING_FINANCE_REVIEW,
            'idempotency_key' => (string) Str::uuid(),
            'created_by' => $admin->id,
        ]);

        $dealA = Deal::query()->create([
            'platform_id' => $platform->id,
            'client_id' => $clientA->id,
            'product_id' => $product->id,
            'plan_type' => 'basic',
            'amount' => 2500,
            'currency' => 'KES',
            'duration' => 'monthly',
            'status' => 'active',
            'activated_at' => now(),
            'expires_at' => now()->addDays(30),
            'origin' => 'manual_payment_bundle',
        ]);

        $dealB = Deal::query()->create([
            'platform_id' => $platform->id,
            'client_id' => $clientB->id,
            'product_id' => $product->id,
            'plan_type' => 'basic',
            'amount' => 2500,
            'currency' => 'KES',
            'duration' => 'monthly',
            'status' => 'active',
            'activated_at' => now(),
            'expires_at' => now()->addDays(30),
            'origin' => 'manual_payment_bundle',
        ]);

        $paymentA = Payment::query()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'manual_payment_bundle_id' => $bundle->id,
            'deal_id' => $dealA->id,
            'client_id' => $clientA->id,
            'amount' => 2500,
            'currency' => 'KES',
            'status' => 'completed',
            'reconciliation_state' => 'manual_review',
            'match_confidence' => 'manual',
            'confirmed_by' => $admin->id,
            'confirmed_at' => now(),
            'phone' => '254700009111',
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => $referenceRoot . '-1',
            'reference_number' => $referenceRoot . '-1',
            'reference_root' => $referenceRoot,
            'reference_sequence' => 1,
            'source' => 'manual_payment_bundle',
            'provider_key' => 'manual_confirmation',
            'provider_environment' => 'production',
        ]);

        $paymentB = Payment::query()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'manual_payment_bundle_id' => $bundle->id,
            'deal_id' => $dealB->id,
            'client_id' => $clientB->id,
            'amount' => 2500,
            'currency' => 'KES',
            'status' => 'completed',
            'reconciliation_state' => 'manual_review',
            'match_confidence' => 'manual',
            'confirmed_by' => $admin->id,
            'confirmed_at' => now(),
            'phone' => '254700009222',
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => $referenceRoot . '-2',
            'reference_number' => $referenceRoot . '-2',
            'reference_root' => $referenceRoot,
            'reference_sequence' => 2,
            'source' => 'manual_payment_bundle',
            'provider_key' => 'manual_confirmation',
            'provider_environment' => 'production',
        ]);

        $dealA->forceFill(['payment_id' => $paymentA->id])->save();
        $dealB->forceFill(['payment_id' => $paymentB->id])->save();

        return [$platform, $admin, $bundle];
    }

    private function createPlatform(): Platform
    {
        return Platform::query()->create([
            'name' => 'Kenya Bundle Void Market',
            'domain' => 'manual-bundle-void-' . Str::random(6) . '.example.test',
            'country' => 'Kenya',
            'timezone' => 'Africa/Nairobi',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
            'wp_api_url' => 'https://manual-bundle-void.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }
}
