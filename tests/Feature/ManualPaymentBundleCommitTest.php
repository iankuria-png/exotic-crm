<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManualPaymentBundleCommitTest extends TestCase
{
    use RefreshDatabase;

    public function test_commit_creates_bundle_child_payments_and_activates_target_rows(): void
    {
        [$platform, $admin] = $this->createAuthContext('admin');
        [$pendingDeal, $expiredDeal] = $this->createEligibleDeals($platform);

        Http::fake([
            'https://manual-bundle-commit.example.test/wp-json/exotic-crm-sync/v1/clients/*/activate' => Http::response(['ok' => true], 200),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/crm/manual-payment-bundles/commit', [
            'platform_id' => $platform->id,
            'reference_root' => 'COMMIT001',
            'total_amount' => 5000,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['deal_id' => $pendingDeal->id, 'allocated_amount' => 2500],
                ['deal_id' => $expiredDeal->id, 'allocated_amount' => 2500],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('bundle.reference_root', 'COMMIT001')
            ->assertJsonPath('bundle.status', 'committed')
            ->assertJsonPath('bundle.audit_state', 'pending_finance_review');

        $bundleId = (int) $response->json('bundle.id');

        $this->assertDatabaseHas('manual_payment_bundles', [
            'id' => $bundleId,
            'status' => 'committed',
        ]);

        $this->assertDatabaseHas('payments', [
            'manual_payment_bundle_id' => $bundleId,
            'transaction_reference' => 'COMMIT001-1',
            'reconciliation_state' => 'manual_review',
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('payments', [
            'manual_payment_bundle_id' => $bundleId,
            'transaction_reference' => 'COMMIT001-2',
            'reconciliation_state' => 'manual_review',
            'status' => 'completed',
        ]);

        $pendingDeal->refresh();
        $this->assertSame('active', $pendingDeal->status);
        $this->assertSame('COMMIT001-1', $pendingDeal->payment_reference);

        $renewedDeal = Deal::query()
            ->where('client_id', $expiredDeal->client_id)
            ->where('origin', 'manual_payment_bundle')
            ->where('status', 'active')
            ->latest('id')
            ->first();

        $this->assertNotNull($renewedDeal);
        $this->assertSame('COMMIT001-2', $renewedDeal->payment_reference);
    }

    public function test_sales_cannot_review_bundle_owned_manual_review_rows(): void
    {
        [$platform, $admin] = $this->createAuthContext('admin');
        [, $sales] = $this->createAuthContext('sales', $platform);
        [$pendingDeal] = $this->createEligibleDeals($platform);

        Http::fake([
            'https://manual-bundle-commit.example.test/wp-json/exotic-crm-sync/v1/clients/*/activate' => Http::response(['ok' => true], 200),
        ]);

        Sanctum::actingAs($admin);

        $commitResponse = $this->postJson('/api/crm/manual-payment-bundles/commit', [
            'platform_id' => $platform->id,
            'reference_root' => 'REVIEW001',
            'total_amount' => 2500,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['deal_id' => $pendingDeal->id, 'allocated_amount' => 2500],
            ],
        ])->assertCreated();

        $paymentId = (int) data_get($commitResponse->json(), 'bundle.payments.0.id');

        Sanctum::actingAs($sales);

        $this->postJson("/api/crm/payments/{$paymentId}/review-state", [
            'state' => 'resolved',
            'reason' => 'Sales tried to resolve bundle row.',
        ])->assertForbidden();
    }

    /**
     * @return array{0: Platform, 1: User}
     */
    private function createAuthContext(string $role, ?Platform $platform = null): array
    {
        $platform ??= $this->createPlatform();
        $user = User::query()->create([
            'name' => ucfirst($role) . ' Bundle User',
            'email' => $role . '-bundle-' . Str::random(6) . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);

        return [$platform, $user];
    }

    /**
     * @return array{0: Deal, 1?: Deal}
     */
    private function createEligibleDeals(Platform $platform): array
    {
        $clientA = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 93000,
            'wp_user_id' => 83000,
            'phone_normalized' => '254700009111',
        ]);

        $clientB = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 93001,
            'wp_user_id' => 83001,
            'phone_normalized' => '254700009222',
        ]);

        $pendingDeal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $clientA->id,
            'status' => 'pending',
            'amount' => 2500,
            'currency' => 'KES',
            'duration' => 'monthly',
        ]);

        $expiredDeal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $clientB->id,
            'status' => 'expired',
            'amount' => 2500,
            'currency' => 'KES',
            'duration' => 'monthly',
        ]);

        return [$pendingDeal, $expiredDeal];
    }

    private function createPlatform(): Platform
    {
        return Platform::query()->create([
            'name' => 'Kenya Bundle Commit Market',
            'domain' => 'manual-bundle-commit-' . Str::random(6) . '.example.test',
            'country' => 'Kenya',
            'timezone' => 'Africa/Nairobi',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
            'wp_api_url' => 'https://manual-bundle-commit.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }
}
