<?php

namespace Tests\Unit\AutoOptimize;

use App\Services\AutoOptimize\AutoOptimizeMarketStats;
use App\Services\WpSyncService;
use App\Services\WpSyncFactory;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AutoOptimizeMarketStatsTest extends TestCase
{
    public function test_aggregates_per_profile_and_computes_averages(): void
    {
        $profiles = [
            ['wp_post_id' => 101, 'views' => 100, 'contact_rate' => 10.0, 'engagement' => 5.0],
            ['wp_post_id' => 102, 'views' => 200, 'contact_rate' => 20.0, 'engagement' => 15.0],
        ];

        $mockWp = $this->createMock(WpSyncService::class);
        $mockWp->expects($this->once())
            ->method('getAnalyticsBulk')
            ->willReturn([
                'profiles' => $profiles,
                'market_averages' => ['views' => 150.0, 'contact_rate' => 15.0, 'engagement' => 10.0],
            ]);

        $service = new AutoOptimizeMarketStats($this->factoryFor($mockWp));
        Cache::flush();

        $result = $service->forPlatform(1, ['from' => '2026-05-01', 'to' => '2026-06-01']);

        $this->assertSame(2, $result['sampleSize']);
        $this->assertEqualsWithDelta(150.0, $result['averages']['views'], 0.01);
        $this->assertEqualsWithDelta(15.0, $result['averages']['contact_rate'], 0.01);
        $this->assertEqualsWithDelta(10.0, $result['averages']['engagement'], 0.01);
        $this->assertArrayHasKey(101, $result['perProfile']);
        $this->assertSame(100.0, $result['perProfile'][101]['views']);
    }

    public function test_paginates_until_last_page(): void
    {
        $page1 = array_map(fn ($i) => ['wp_post_id' => $i, 'views' => 10, 'contact_rate' => 5, 'engagement' => 2], range(1, 200));
        $page2 = array_map(fn ($i) => ['wp_post_id' => $i, 'views' => 10, 'contact_rate' => 5, 'engagement' => 2], range(201, 250));

        $mockWp = $this->createMock(WpSyncService::class);
        $mockWp->expects($this->exactly(2))
            ->method('getAnalyticsBulk')
            ->willReturnOnConsecutiveCalls(
                ['profiles' => $page1, 'market_averages' => ['views' => 10.0, 'contact_rate' => 5.0, 'engagement' => 2.0]],
                ['profiles' => $page2, 'market_averages' => ['views' => 10.0, 'contact_rate' => 5.0, 'engagement' => 2.0]],
            );

        $service = new AutoOptimizeMarketStats($this->factoryFor($mockWp));
        Cache::flush();

        $result = $service->forPlatform(1, ['from' => '2026-05-01', 'to' => '2026-06-01']);

        $this->assertSame(250, $result['sampleSize']);
    }

    public function test_returns_zero_averages_for_empty_response(): void
    {
        $mockWp = $this->createMock(WpSyncService::class);
        $mockWp->method('getAnalyticsBulk')->willReturn(['profiles' => []]);

        $service = new AutoOptimizeMarketStats($this->factoryFor($mockWp));
        Cache::flush();

        $result = $service->forPlatform(1, ['from' => '2026-05-01', 'to' => '2026-06-01']);

        $this->assertSame(0, $result['sampleSize']);
        $this->assertSame(0.0, $result['averages']['views']);
    }

    public function test_caches_result_for_5_minutes(): void
    {
        $profiles = [['wp_post_id' => 1, 'views' => 50, 'contact_rate' => 5, 'engagement' => 2]];

        $mockWp = $this->createMock(WpSyncService::class);
        // Should only be called once even if we call forPlatform twice
        $mockWp->expects($this->once())
            ->method('getAnalyticsBulk')
            ->willReturn([
                'profiles' => $profiles,
                'market_averages' => ['views' => 50.0, 'contact_rate' => 5.0, 'engagement' => 2.0],
            ]);

        $service = new AutoOptimizeMarketStats($this->factoryFor($mockWp));
        Cache::flush();

        $service->forPlatform(1, ['from' => '2026-05-01', 'to' => '2026-06-01']);
        $service->forPlatform(1, ['from' => '2026-05-01', 'to' => '2026-06-01']);
    }

    public function test_prefers_server_market_averages_over_local_recompute(): void
    {
        $profiles = [
            ['wp_post_id' => 101, 'views' => 100, 'contact_rate' => 10.0, 'engagement' => 5.0],
            ['wp_post_id' => 102, 'views' => 200, 'contact_rate' => 20.0, 'engagement' => 15.0],
        ];

        $mockWp = $this->createMock(WpSyncService::class);
        $mockWp->expects($this->once())
            ->method('getAnalyticsBulk')
            ->willReturn([
                'profiles' => $profiles,
                'market_averages' => ['views' => 999.0, 'contact_rate' => 33.3, 'engagement' => 77.0],
            ]);

        $service = new AutoOptimizeMarketStats($this->factoryFor($mockWp));
        Cache::flush();

        $result = $service->forPlatform(1, ['from' => '2026-05-01', 'to' => '2026-06-01']);

        $this->assertSame(999.0, $result['averages']['views']);
        $this->assertSame(33.3, $result['averages']['contact_rate']);
        $this->assertSame(77.0, $result['averages']['engagement']);
    }

    /**
     * Regression: the analytics call MUST be scoped to the requested platform
     * via WpSyncFactory::forPlatform($platformId) — not a container-injected
     * WpSyncService bound to an empty Platform (which silently misrouted every
     * WP call to an empty URL and made selection return 0 in production).
     */
    public function test_resolves_wpsync_for_the_requested_platform(): void
    {
        $mockWp = $this->createMock(WpSyncService::class);
        $mockWp->method('getAnalyticsBulk')->willReturn(['profiles' => [
            ['wp_post_id' => 1, 'views' => 10, 'contact_rate' => 1, 'engagement' => 1],
        ]]);

        $factory = $this->createMock(WpSyncFactory::class);
        $factory->expects($this->once())
            ->method('forPlatform')
            ->with(7) // the platform id passed to forPlatform()
            ->willReturn($mockWp);

        Cache::flush();
        (new AutoOptimizeMarketStats($factory))->forPlatform(7, ['from' => '2026-05-01', 'to' => '2026-06-01']);
    }

    private function factoryFor(WpSyncService $mock): WpSyncFactory
    {
        $factory = $this->createMock(WpSyncFactory::class);
        $factory->method('forPlatform')->willReturn($mock);
        return $factory;
    }
}
