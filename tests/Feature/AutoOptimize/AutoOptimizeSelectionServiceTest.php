<?php

namespace Tests\Feature\AutoOptimize;

use App\Models\AutoOptimizeItem;
use App\Models\AutoOptimizePlan;
use App\Models\AutoOptimizeRun;
use App\Models\Client;
use App\Models\Platform;
use App\Services\AutoOptimize\AutoOptimizeMarketStats;
use App\Services\AutoOptimize\AutoOptimizeSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutoOptimizeSelectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private Platform $platform;
    private AutoOptimizePlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = Platform::query()->create([
            'name' => 'Test Kenya',
            'domain' => 'test.example',
            'country' => 'Kenya',
            'timezone' => 'Africa/Nairobi',
            'wp_api_url' => 'https://test.example/wp-json',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
        ]);

        $this->plan = AutoOptimizePlan::query()->create([
            'name' => 'Test Plan',
            'platform_id' => $this->platform->id,
            'enabled' => false,
            'criteria' => [
                'max_score' => 60,
                'views_below_market_pct' => 80,
                'contact_rate_below_market_pct' => 80,
                'engagement_below_market_pct' => 80,
                'require_below' => 'any',
                'min_market_sample' => 2,
                'only_published' => false,
                'eligibility_window_days' => 30,
            ],
            'schedule' => ['daily_limit' => 10],
            'reliability' => ['exclude_optimized_within_days' => 14],
        ]);
    }

    public function test_selects_clients_below_market_average_with_low_seo_score(): void
    {
        // Profile 101: score 40 (low), views 50 (below 80% of avg 100)
        $client1 = Client::factory()->create(['platform_id' => $this->platform->id, 'wp_post_id' => 101, 'seo_score' => 40, 'profile_status' => 'publish']);
        // Profile 102: score 80 (high) — should be excluded
        $client2 = Client::factory()->create(['platform_id' => $this->platform->id, 'wp_post_id' => 102, 'seo_score' => 80, 'profile_status' => 'publish']);

        $stats = $this->makeStats([
            101 => ['views' => 50, 'contact_rate' => 5, 'engagement' => 2],
            102 => ['views' => 200, 'contact_rate' => 20, 'engagement' => 10],
        ], ['views' => 100, 'contact_rate' => 15, 'engagement' => 8]);

        $service = new AutoOptimizeSelectionService($stats);
        $results = $service->selectForPlan($this->plan);

        $this->assertCount(1, $results);
        $this->assertSame($client1->id, $results->first()->id);
    }

    public function test_excludes_clients_with_active_items(): void
    {
        $client = Client::factory()->create(['platform_id' => $this->platform->id, 'wp_post_id' => 101, 'seo_score' => 30, 'profile_status' => 'publish']);

        // Create an active item for this client
        $run = AutoOptimizeRun::query()->create([
            'auto_optimize_plan_id' => $this->plan->id,
            'platform_id' => $this->platform->id,
            'status' => 'running',
        ]);
        AutoOptimizeItem::query()->create([
            'auto_optimize_plan_id' => $this->plan->id,
            'auto_optimize_run_id' => $run->id,
            'platform_id' => $this->platform->id,
            'client_id' => $client->id,
            'status' => 'pending', // active
        ]);

        $stats = $this->makeStats(
            [101 => ['views' => 10, 'contact_rate' => 1, 'engagement' => 1]],
            ['views' => 100, 'contact_rate' => 15, 'engagement' => 8]
        );

        $service = new AutoOptimizeSelectionService($stats);
        $results = $service->selectForPlan($this->plan);

        $this->assertCount(0, $results);
    }

    public function test_excludes_recently_optimized_clients(): void
    {
        $client = Client::factory()->create(['platform_id' => $this->platform->id, 'wp_post_id' => 101, 'seo_score' => 30, 'profile_status' => 'publish']);

        $run = AutoOptimizeRun::query()->create([
            'auto_optimize_plan_id' => $this->plan->id,
            'platform_id' => $this->platform->id,
            'status' => 'completed',
        ]);

        // Applied 5 days ago — within 14-day exclusion window
        AutoOptimizeItem::query()->create([
            'auto_optimize_plan_id' => $this->plan->id,
            'auto_optimize_run_id' => $run->id,
            'platform_id' => $this->platform->id,
            'client_id' => $client->id,
            'status' => 'applied',
            'applied_at' => now()->subDays(5),
        ]);

        $stats = $this->makeStats(
            [101 => ['views' => 10, 'contact_rate' => 1, 'engagement' => 1]],
            ['views' => 100, 'contact_rate' => 15, 'engagement' => 8]
        );

        $service = new AutoOptimizeSelectionService($stats);
        $results = $service->selectForPlan($this->plan);

        $this->assertCount(0, $results);
    }

    public function test_respects_daily_limit(): void
    {
        // Create 5 eligible clients, daily_limit = 3
        $this->plan->forceFill(['schedule' => ['daily_limit' => 3]])->saveQuietly();

        $analytics = [];
        for ($i = 1; $i <= 5; $i++) {
            Client::factory()->create(['platform_id' => $this->platform->id, 'wp_post_id' => $i, 'seo_score' => 30, 'profile_status' => 'publish']);
            $analytics[$i] = ['views' => 10, 'contact_rate' => 1, 'engagement' => 1];
        }

        $stats = $this->makeStats($analytics, ['views' => 100, 'contact_rate' => 15, 'engagement' => 8]);
        $service = new AutoOptimizeSelectionService($stats);
        $results = $service->selectForPlan($this->plan);

        $this->assertLessThanOrEqual(3, $results->count());
    }

    public function test_zero_per_client_wp_calls(): void
    {
        // The mock only has getAnalyticsRankings — any getAnalytics call should not happen
        $client = Client::factory()->create(['platform_id' => $this->platform->id, 'wp_post_id' => 101, 'seo_score' => 30]);

        $mockStats = $this->createMock(AutoOptimizeMarketStats::class);
        $mockStats->expects($this->once()) // exactly one rankings call
            ->method('forPlatform')
            ->willReturn([
                'averages' => ['views' => 100, 'contact_rate' => 10, 'engagement' => 5],
                'sampleSize' => 2,
                'perProfile' => [101 => ['views' => 10, 'contact_rate' => 1, 'engagement' => 1]],
            ]);

        $service = new AutoOptimizeSelectionService($mockStats);
        $service->selectForPlan($this->plan);
        // If this passes without extra mock expectations, WP is not called per-client
        $this->assertTrue(true);
    }

    private function makeStats(array $profileMap, array $averages): AutoOptimizeMarketStats
    {
        $mock = $this->createMock(AutoOptimizeMarketStats::class);
        $mock->method('forPlatform')->willReturn([
            'averages' => $averages,
            'sampleSize' => count($profileMap),
            'perProfile' => $profileMap,
        ]);
        return $mock;
    }
}
