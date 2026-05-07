<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\WalletSettingsService;
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
        $bundleItems = $this->createBundleItems($platform);

        Http::fake([
            'https://manual-bundle-commit.example.test/wp-json/exotic-crm-sync/v1/clients/*/activate' => Http::response(['ok' => true], 200),
        ]);

        app(WalletSettingsService::class)->updateDiscountConfig([
            'max_percentage_by_platform' => [
                (string) $platform->id => 20,
            ],
        ], $admin->id);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/crm/manual-payment-bundles/commit', [
            'platform_id' => $platform->id,
            'reference_root' => 'COMMIT001',
            'total_amount' => 5000,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                [
                    'client_id' => $bundleItems[0]['client']->id,
                    'product_id' => $bundleItems[0]['product']->id,
                    'duration' => 'monthly',
                    'allocated_amount' => 2500,
                ],
                [
                    'client_id' => $bundleItems[1]['client']->id,
                    'product_id' => $bundleItems[1]['product']->id,
                    'duration' => 'monthly',
                    'allocated_amount' => 2500,
                ],
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

        $firstDeal = Deal::query()->where('payment_reference', 'COMMIT001-1')->firstOrFail();
        $secondDeal = Deal::query()->where('payment_reference', 'COMMIT001-2')->firstOrFail();

        $this->assertSame('active', $firstDeal->status);
        $this->assertSame('manual_payment_bundle', $firstDeal->origin);
        $this->assertGreaterThan(20, strlen((string) $firstDeal->origin));
        $this->assertSame('agent_manual', $firstDeal->discount_source);
        $this->assertSame(16.67, round((float) $firstDeal->discount_percentage, 2));
        $this->assertSame(3000.0, (float) $firstDeal->original_amount);

        $this->assertSame('active', $secondDeal->status);
        $this->assertSame('manual_payment_bundle', $secondDeal->origin);
        $this->assertGreaterThan(20, strlen((string) $secondDeal->origin));
        $this->assertSame('agent_manual', $secondDeal->discount_source);
        $this->assertSame(16.67, round((float) $secondDeal->discount_percentage, 2));
        $this->assertSame(3000.0, (float) $secondDeal->original_amount);
    }

    public function test_sales_cannot_review_bundle_owned_manual_review_rows(): void
    {
        [$platform, $admin] = $this->createAuthContext('admin');
        [, $sales] = $this->createAuthContext('sales', $platform);
        $bundleItems = $this->createBundleItems($platform);

        Http::fake([
            'https://manual-bundle-commit.example.test/wp-json/exotic-crm-sync/v1/clients/*/activate' => Http::response(['ok' => true], 200),
        ]);

        app(WalletSettingsService::class)->updateDiscountConfig([
            'max_percentage_by_platform' => [
                (string) $platform->id => 20,
            ],
        ], $admin->id);

        Sanctum::actingAs($admin);

        $commitResponse = $this->postJson('/api/crm/manual-payment-bundles/commit', [
            'platform_id' => $platform->id,
            'reference_root' => 'REVIEW001',
            'total_amount' => 2500,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                [
                    'client_id' => $bundleItems[0]['client']->id,
                    'product_id' => $bundleItems[0]['product']->id,
                    'duration' => 'monthly',
                    'allocated_amount' => 2500,
                ],
            ],
        ])->assertCreated();

        $paymentId = (int) data_get($commitResponse->json(), 'bundle.payments.0.id');

        Sanctum::actingAs($sales);

        $this->postJson("/api/crm/payments/{$paymentId}/review-state", [
            'state' => 'resolved',
            'reason' => 'Sales tried to resolve bundle row.',
        ])->assertForbidden();
    }

    public function test_commit_rolls_back_created_rows_when_later_child_activation_fails_and_compensation_succeeds(): void
    {
        [$platform, $admin] = $this->createAuthContext('admin');
        $bundleItems = $this->createBundleItems($platform);

        Http::fake(function ($request) {
            $url = (string) $request->url();

            if (str_contains($url, '/clients/93000/activate')) {
                return Http::response(['ok' => true], 200);
            }

            if (str_contains($url, '/clients/93001/activate')) {
                return Http::response(['message' => 'WordPress activation failed.'], 500);
            }

            if (str_contains($url, '/clients/93000/deactivate')) {
                return Http::response(['ok' => true], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        app(WalletSettingsService::class)->updateDiscountConfig([
            'max_percentage_by_platform' => [
                (string) $platform->id => 20,
            ],
        ], $admin->id);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/crm/manual-payment-bundles/commit', [
            'platform_id' => $platform->id,
            'reference_root' => 'FAILROLL1',
            'total_amount' => 5000,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                [
                    'client_id' => $bundleItems[0]['client']->id,
                    'product_id' => $bundleItems[0]['product']->id,
                    'duration' => 'monthly',
                    'allocated_amount' => 2500,
                ],
                [
                    'client_id' => $bundleItems[1]['client']->id,
                    'product_id' => $bundleItems[1]['product']->id,
                    'duration' => 'monthly',
                    'allocated_amount' => 2500,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('bundle');

        $this->assertDatabaseMissing('manual_payment_bundles', [
            'reference_root' => 'FAILROLL1',
        ]);

        $this->assertDatabaseMissing('payments', [
            'transaction_reference' => 'FAILROLL1-1',
        ]);

        $this->assertDatabaseMissing('deals', [
            'payment_reference' => 'FAILROLL1-1',
        ]);
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
     * @return array<int, array{client: Client, product: Product}>
     */
    private function createBundleItems(Platform $platform): array
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

        $productA = Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Premium Bundle Plan A',
            'display_name' => 'Premium Bundle Plan A',
            'slug' => 'premium-bundle-plan-a-' . Str::lower(Str::random(6)),
            'tier' => 'premium',
            'weekly_price' => 750,
            'biweekly_price' => 1500,
            'monthly_price' => 3000,
            'currency' => 'KES',
            'is_active' => true,
        ]);

        $productB = Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Premium Bundle Plan B',
            'display_name' => 'Premium Bundle Plan B',
            'slug' => 'premium-bundle-plan-b-' . Str::lower(Str::random(6)),
            'tier' => 'premium',
            'weekly_price' => 750,
            'biweekly_price' => 1500,
            'monthly_price' => 3000,
            'currency' => 'KES',
            'is_active' => true,
        ]);

        return [
            ['client' => $clientA, 'product' => $productA],
            ['client' => $clientB, 'product' => $productB],
        ];
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
