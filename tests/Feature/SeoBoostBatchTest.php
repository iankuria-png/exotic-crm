<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\SeoBoostBatch;
use App\Models\SeoBoostItem;
use App\Models\User;
use App\Services\WalletSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SeoBoostBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_ranks_inactive_candidates_by_seo_quality_and_excludes_unsafe_profiles(): void
    {
        $platform = Platform::factory()->create();
        $best = $this->inactiveClient($platform, 41001, 'Kilimani', [
            'name' => 'Best Candidate',
            'seo_score' => 94,
            'verified' => true,
            'display_image_url' => 'https://example.test/best.jpg',
        ]);
        $second = $this->inactiveClient($platform, 41002, 'Kilimani', [
            'name' => 'Second Candidate',
            'seo_score' => 71,
        ]);
        $this->inactiveClient($platform, 41003, 'Kilimani', [
            'name' => 'Risky Candidate',
            'seo_score' => 99,
            'is_high_risk' => true,
        ]);
        $active = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 41004,
            'city' => 'Kilimani',
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'seo_score' => 100,
        ]);
        Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $active->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->userFor($platform));

        $response = $this->postJson('/api/crm/seo-boost/preview', [
            'platform_id' => $platform->id,
            'targets' => [[
                'canonical_key' => 'kilimani',
                'display_city' => 'Kilimani',
                'target_count' => 2,
            ]],
        ])->assertOk();

        $this->assertSame(2, $response->json('eligible_count'));
        $this->assertSame([$best->id, $second->id], $response->json('selected_client_ids'));
        $this->assertSame($best->id, $response->json('candidates.0.client_id'));
        $this->assertSame($second->id, $response->json('candidates.1.client_id'));
    }

    public function test_create_requires_valid_free_trial_pin_before_creating_batch(): void
    {
        $platform = Platform::factory()->create();
        $product = Product::factory()->create(['platform_id' => $platform->id]);
        $client = $this->inactiveClient($platform, 41005, 'Westlands', ['seo_score' => 80]);
        $user = $this->userFor($platform);
        app(WalletSettingsService::class)->updateFreeTrialPin('4821', $user->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/crm/seo-boost/batches', [
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'duration_days' => 14,
            'targets' => [[
                'canonical_key' => 'westlands',
                'display_city' => 'Westlands',
                'target_count' => 1,
            ]],
            'selected_client_ids' => [$client->id],
            'free_trial_pin' => '1111',
        ])->assertStatus(422);

        $this->assertDatabaseCount('seo_boost_batches', 0);
        $this->assertDatabaseMissing('deals', [
            'client_id' => $client->id,
            'origin' => 'seo_boost',
        ]);
    }

    public function test_create_batch_activates_selected_clients_and_tracks_deals(): void
    {
        $platform = Platform::factory()->create();
        $product = Product::factory()->create(['platform_id' => $platform->id, 'name' => 'Premium']);
        $price = ProductPrice::factory()->create([
            'product_id' => $product->id,
            'duration_days' => 14,
            'duration_label' => '14 Days',
            'price' => 1500,
        ]);
        $client = $this->inactiveClient($platform, 41006, 'Kilimani', [
            'seo_score' => 92,
            'verified' => true,
        ]);
        $user = $this->userFor($platform);
        app(WalletSettingsService::class)->updateFreeTrialPin('4821', $user->id);
        $this->fakeWpActivation($platform, $client, 14);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/seo-boost/batches', [
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'product_price_id' => $price->id,
            'duration_days' => 14,
            'targets' => [[
                'canonical_key' => 'kilimani',
                'display_city' => 'Kilimani',
                'target_count' => 1,
            ]],
            'selected_client_ids' => [$client->id],
            'free_trial_pin' => '4821',
            'notes' => 'Fill weak city coverage',
        ])->assertCreated();

        $batchId = $response->json('batch.id');
        $this->assertDatabaseHas('seo_boost_batches', [
            'id' => $batchId,
            'status' => 'active',
            'selected_count' => 1,
            'activated_count' => 1,
            'failed_count' => 0,
        ]);
        $this->assertDatabaseHas('seo_boost_items', [
            'batch_id' => $batchId,
            'client_id' => $client->id,
            'status' => 'active',
            'quality_score' => 92,
        ]);
        $this->assertDatabaseHas('deals', [
            'client_id' => $client->id,
            'origin' => 'seo_boost',
            'seo_boost_batch_id' => $batchId,
            'is_free_trial' => true,
            'amount' => 0,
            'status' => 'active',
        ]);
        $this->assertSame('publish', $client->fresh()->profile_status);
    }

    public function test_existing_expiry_reconciliation_marks_seo_boost_items_completed(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 41007,
            'city' => 'Kilimani',
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'escort_expire' => now()->subDays(2)->timestamp,
        ]);
        $batch = SeoBoostBatch::create([
            'platform_id' => $platform->id,
            'created_by' => $this->userFor($platform)->id,
            'product_id' => Product::factory()->create(['platform_id' => $platform->id])->id,
            'duration_days' => 7,
            'status' => 'active',
            'target_count' => 1,
            'selected_count' => 1,
            'activated_count' => 1,
        ]);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'active',
            'origin' => 'seo_boost',
            'seo_boost_batch_id' => $batch->id,
        ]);
        SeoBoostItem::create([
            'batch_id' => $batch->id,
            'client_id' => $client->id,
            'deal_id' => $deal->id,
            'canonical_key' => 'kilimani',
            'display_city' => 'Kilimani',
            'quality_score' => 90,
            'status' => 'active',
        ]);
        $this->fakeWpDeactivation($platform, $client);

        $this->artisan('crm:reconcile-expired-subscriptions')->assertExitCode(0);

        $this->assertSame('expired', SeoBoostItem::query()->first()->status);
        $freshBatch = $batch->fresh();
        $this->assertSame('completed', $freshBatch->status);
        $this->assertSame(1, (int) $freshBatch->expired_count);
    }

    private function inactiveClient(Platform $platform, int $wpPostId, string $city, array $overrides = []): Client
    {
        return Client::factory()->create(array_merge([
            'platform_id' => $platform->id,
            'wp_post_id' => $wpPostId,
            'wp_user_id' => $wpPostId + 1000,
            'city' => $city,
            'profile_status' => 'private',
            'needs_payment' => true,
            'notactive' => false,
            'source_presence_status' => 'present',
        ], $overrides));
    }

    private function userFor(Platform $platform, string $role = 'sales'): User
    {
        return User::factory()->create([
            'role' => $role,
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);
    }

    private function fakeWpActivation(Platform $platform, Client $client, int $durationDays): void
    {
        $base = rtrim((string) $platform->wp_api_url, '/');
        $expiry = now()->addDays($durationDays)->timestamp;

        Http::fake([
            "{$base}/clients/{$client->wp_post_id}/activate" => Http::response([
                'success' => true,
                'escort_expire' => $expiry,
            ], 200),
            "{$base}/clients/{$client->wp_post_id}" => Http::response([
                'wp_post_id' => (int) $client->wp_post_id,
                'wp_user_id' => (int) $client->wp_user_id,
                'name' => $client->name,
                'phone' => $client->phone_normalized,
                'email' => $client->email,
                'city' => $client->city,
                'post_status' => 'publish',
                'needs_payment' => false,
                'notactive' => false,
                'premium' => true,
                'premium_expire' => $expiry,
                'featured' => false,
                'escort_expire' => $expiry,
                'verified' => (bool) $client->verified,
                'main_image_url' => $client->main_image_url,
                'seo_quality_score' => $client->seo_score,
                'seo_quality_score_breakdown' => $client->seo_score_breakdown,
            ], 200),
        ]);
    }

    private function fakeWpDeactivation(Platform $platform, Client $client): void
    {
        $base = rtrim((string) $platform->wp_api_url, '/');

        Http::fake([
            "{$base}/clients/{$client->wp_post_id}/deactivate" => Http::response(['success' => true], 200),
            "{$base}/clients/{$client->wp_post_id}" => Http::response([
                'wp_post_id' => (int) $client->wp_post_id,
                'wp_user_id' => (int) $client->wp_user_id,
                'name' => $client->name,
                'phone' => $client->phone_normalized,
                'email' => $client->email,
                'city' => $client->city,
                'post_status' => 'private',
                'needs_payment' => true,
                'notactive' => false,
                'premium' => false,
                'featured' => false,
                'escort_expire' => null,
            ], 200),
        ]);
    }
}
