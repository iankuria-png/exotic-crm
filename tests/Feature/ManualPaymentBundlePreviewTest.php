<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\ManualPaymentBundle;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\WalletSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManualPaymentBundlePreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_returns_sequence_and_action_breakdown_for_selected_rows(): void
    {
        [$platform, $user] = $this->createAuthContext();
        [$pendingDeal, $expiredDeal] = $this->createEligibleDeals($platform);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/manual-payment-bundles/preview', [
            'platform_id' => $platform->id,
            'reference_root' => '  smoke001  ',
            'total_amount' => 5000,
            'items' => [
                [
                    'client_id' => $pendingDeal->client_id,
                    'product_id' => $pendingDeal->product_id,
                    'duration' => 'monthly',
                    'allocated_amount' => 2500,
                ],
                [
                    'client_id' => $expiredDeal->client_id,
                    'product_id' => $expiredDeal->product_id,
                    'duration' => 'monthly',
                    'allocated_amount' => 2500,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('reference_root', 'SMOKE001')
            ->assertJsonPath('allocated_total', 5000)
            ->assertJsonPath('shortfall_amount', 0)
            ->assertJsonPath('unallocated_amount', 0)
            ->assertJsonPath('items.0.child_reference', 'SMOKE001-1')
            ->assertJsonPath('items.1.child_reference', 'SMOKE001-2');
    }

    public function test_preview_rejects_mixed_market_selection(): void
    {
        [$platform, $user] = $this->createAuthContext();
        [$pendingDeal] = $this->createEligibleDeals($platform);

        $otherPlatform = $this->createPlatform('Uganda');
        $otherClient = Client::factory()->create([
            'platform_id' => $otherPlatform->id,
            'wp_post_id' => 91002,
            'wp_user_id' => 81002,
        ]);
        $otherDeal = Deal::factory()->create([
            'platform_id' => $otherPlatform->id,
            'client_id' => $otherClient->id,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/manual-payment-bundles/preview', [
            'platform_id' => $platform->id,
            'reference_root' => 'MIXED001',
            'total_amount' => 5000,
            'items' => [
                [
                    'client_id' => $pendingDeal->client_id,
                    'product_id' => $pendingDeal->product_id,
                    'duration' => 'monthly',
                    'allocated_amount' => 2500,
                ],
                [
                    'client_id' => $otherDeal->client_id,
                    'product_id' => $otherDeal->product_id,
                    'duration' => 'monthly',
                    'allocated_amount' => 2500,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_preview_returns_conflict_when_reference_root_already_belongs_to_bundle(): void
    {
        [$platform, $user] = $this->createAuthContext();
        [$pendingDeal] = $this->createEligibleDeals($platform);

        ManualPaymentBundle::factory()->create([
            'platform_id' => $platform->id,
            'reference_root' => 'EXISTING001',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/manual-payment-bundles/preview', [
            'platform_id' => $platform->id,
            'reference_root' => 'EXISTING001',
            'total_amount' => 2500,
            'items' => [
                [
                    'client_id' => $pendingDeal->client_id,
                    'product_id' => $pendingDeal->product_id,
                    'duration' => 'monthly',
                    'allocated_amount' => 2500,
                ],
            ],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('conflict.reference_root', 'EXISTING001');
    }

    public function test_preview_validates_discount_cap_for_shortfall_allocations(): void
    {
        [$platform, $user] = $this->createAuthContext();
        [$pendingDeal] = $this->createEligibleDeals($platform);

        app(WalletSettingsService::class)->updateDiscountConfig([
            'max_percentage_by_platform' => [
                (string) $platform->id => 10,
            ],
        ], $user->id);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/manual-payment-bundles/preview', [
            'platform_id' => $platform->id,
            'reference_root' => 'DISC001',
            'total_amount' => 1500,
            'items' => [
                [
                    'client_id' => $pendingDeal->client_id,
                    'product_id' => $pendingDeal->product_id,
                    'duration' => 'monthly',
                    'allocated_amount' => 1500,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    /**
     * @return array{0: Platform, 1: User}
     */
    private function createAuthContext(): array
    {
        $platform = $this->createPlatform();
        $user = User::query()->create([
            'name' => 'Bundle Preview Admin',
            'email' => 'bundle-preview-' . Str::random(6) . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);

        return [$platform, $user];
    }

    /**
     * @return array{0: Deal, 1: Deal}
     */
    private function createEligibleDeals(Platform $platform): array
    {
        $clientA = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 91000,
            'wp_user_id' => 81000,
            'phone_normalized' => '254700000111',
        ]);

        $clientB = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 91001,
            'wp_user_id' => 81001,
            'phone_normalized' => '254700000222',
        ]);

        $productA = Product::factory()->create([
            'platform_id' => $platform->id,
            'monthly_price' => 2500,
            'currency' => 'KES',
        ]);

        $productB = Product::factory()->create([
            'platform_id' => $platform->id,
            'monthly_price' => 2500,
            'currency' => 'KES',
        ]);

        $pendingDeal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $clientA->id,
            'product_id' => $productA->id,
            'status' => 'pending',
            'amount' => 2500,
            'currency' => 'KES',
            'duration' => 'monthly',
        ]);

        $expiredDeal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $clientB->id,
            'product_id' => $productB->id,
            'status' => 'expired',
            'amount' => 2500,
            'currency' => 'KES',
            'duration' => 'monthly',
        ]);

        return [$pendingDeal, $expiredDeal];
    }

    private function createPlatform(string $country = 'Kenya'): Platform
    {
        return Platform::query()->create([
            'name' => $country . ' Manual Bundle Market',
            'domain' => 'manual-bundle-' . Str::random(6) . '.example.test',
            'country' => $country,
            'timezone' => 'Africa/Nairobi',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
            'wp_api_url' => 'https://manual-bundle.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }
}
