<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\RetentionMetricSnapshot;
use App\Models\User;
use App\Services\ClientRetentionInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientRetentionInsightTest extends TestCase
{
    use RefreshDatabase;

    public function test_retention_insight_endpoint_and_clients_filters_return_watchlist_data(): void
    {
        $platform = $this->createPlatform();
        $product = $this->createProduct($platform);
        $admin = $this->createAdminUser();
        $riskyClient = $this->seedRetentionCohort($platform, $product);

        $service = app(ClientRetentionInsightService::class);
        $service->refreshAll([$platform->id]);
        $service->recordDailyMetricSnapshots(now(), [$platform->id]);

        Sanctum::actingAs($admin);

        $insightResponse = $this->getJson("/api/crm/clients/{$riskyClient->id}/retention-insight");
        $insightResponse->assertOk()
            ->assertJsonPath('client_id', $riskyClient->id)
            ->assertJsonPath('is_watchlist', true)
            ->assertJsonPath('primary_tag', 'Payment Friction');

        $watchResponse = $this->getJson("/api/crm/clients?platform_id={$platform->id}&retention_band=watch");
        $watchResponse->assertOk()
            ->assertJsonPath('stats.retention_watch', 1);

        $watchIds = collect($watchResponse->json('data'))->pluck('id')->all();
        $this->assertContains($riskyClient->id, $watchIds);

        $behaviorResponse = $this->getJson("/api/crm/clients?platform_id={$platform->id}&behavior_tag=Payment%20Friction");
        $behaviorResponse->assertOk();

        $behaviorIds = collect($behaviorResponse->json('data'))->pluck('id')->all();
        $this->assertContains($riskyClient->id, $behaviorIds);
    }

    public function test_dashboard_summary_includes_retention_watch_and_logo_churn_snapshot(): void
    {
        $platform = $this->createPlatform();
        $product = $this->createProduct($platform);
        $admin = $this->createAdminUser();
        $riskyClient = $this->seedRetentionCohort($platform, $product);

        $service = app(ClientRetentionInsightService::class);
        $service->refreshAll([$platform->id]);
        $service->recordDailyMetricSnapshots(now(), [$platform->id]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/crm/dashboard?platform_id={$platform->id}");
        $snapshot = RetentionMetricSnapshot::query()
            ->where('platform_id', $platform->id)
            ->latest('snapshot_date')
            ->firstOrFail();

        $response->assertOk()
            ->assertJsonPath('retention_summary.watch_count', 1);

        $this->assertGreaterThan(0, (int) $snapshot->active_baseline_count);
        $this->assertSame(
            (float) $snapshot->logo_churn_30d,
            (float) $response->json('retention_summary.logo_churn_30d')
        );
        $this->assertArrayHasKey('Payment Friction', $response->json('retention_summary.behavior_distribution'));

        $topWatchIds = collect($response->json('retention_summary.top_watch_clients'))->pluck('client_id')->all();
        $this->assertContains($riskyClient->id, $topWatchIds);
    }

    public function test_retention_weights_redistribute_when_only_one_component_is_available(): void
    {
        $platform = $this->createPlatform();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'profile_status' => 'publish',
            'last_online_at' => now()->subDays(40)->timestamp,
        ]);

        $insight = app(ClientRetentionInsightService::class)->refreshForClient($client);

        $this->assertSame(100.0, (float) data_get($insight->component_scores, 'engagement_recency.effective_weight'));
        $this->assertArrayNotHasKey('payments', $insight->component_scores);
        $this->assertArrayNotHasKey('subscription_lifecycle', $insight->component_scores);
    }

    private function seedRetentionCohort(Platform $platform, Product $product): Client
    {
        for ($index = 0; $index < 15; $index++) {
            $client = Client::factory()->create([
                'platform_id' => $platform->id,
                'profile_status' => 'publish',
                'last_online_at' => now()->subDays(1)->timestamp,
                'phone_normalized' => '254711' . str_pad((string) $index, 6, '0', STR_PAD_LEFT),
            ]);

            $deal = Deal::factory()->create([
                'platform_id' => $platform->id,
                'client_id' => $client->id,
                'product_id' => $product->id,
                'plan_type' => 'basic',
                'amount' => 1500,
                'currency' => 'KES',
                'duration' => 'monthly',
                'status' => 'active',
                'activated_at' => now()->subDays(35),
                'expires_at' => now()->addDays(10),
            ]);

            Payment::factory()->create([
                'platform_id' => $platform->id,
                'product_id' => $product->id,
                'client_id' => $client->id,
                'deal_id' => $deal->id,
                'phone' => $client->phone_normalized,
                'amount' => 1500,
                'currency' => 'KES',
                'status' => 'completed',
                'created_at' => now()->subDays(5),
                'completed_at' => now()->subDays(5),
            ]);
        }

        $riskyClient = Client::factory()->create([
            'platform_id' => $platform->id,
            'profile_status' => 'private',
            'last_online_at' => now()->subDays(65)->timestamp,
            'phone_normalized' => '254799000999',
        ]);

        Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $riskyClient->id,
            'product_id' => $product->id,
            'plan_type' => 'basic',
            'amount' => 1500,
            'currency' => 'KES',
            'duration' => 'monthly',
            'status' => 'cancelled',
            'activated_at' => now()->subDays(40),
            'expires_at' => now()->subDays(10),
            'created_at' => now()->subDays(40),
            'updated_at' => now()->subDays(5),
        ]);

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $riskyClient->id,
            'phone' => $riskyClient->phone_normalized,
            'amount' => 1500,
            'currency' => 'KES',
            'status' => 'failed',
            'created_at' => now()->subDays(3),
        ]);

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $riskyClient->id,
            'phone' => $riskyClient->phone_normalized,
            'amount' => 1500,
            'currency' => 'KES',
            'status' => 'failed',
            'created_at' => now()->subDays(1),
        ]);

        return $riskyClient;
    }

    private function createPlatform(): Platform
    {
        return Platform::factory()->create([
            'name' => 'Retention Market',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
        ]);
    }

    private function createProduct(Platform $platform): Product
    {
        return Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Basic Plan',
            'display_name' => 'Basic Plan',
            'slug' => 'basic-plan-' . $platform->id,
            'tier' => 'basic',
            'weekly_price' => 500,
            'biweekly_price' => 1000,
            'monthly_price' => 1500,
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
            'email' => 'retention-admin-' . uniqid('', true) . '@example.test',
        ]);
    }
}
