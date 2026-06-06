<?php

namespace Tests\Unit\AutoOptimize;

use App\Services\AutoOptimize\AutoOptimizeMarketStats;
use App\Services\WpSyncService;
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
            ->method('getAnalyticsRankings')
            ->willReturn(['data' => $profiles]);

        $service = new AutoOptimizeMarketStats($mockWp);
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
        $page1 = array_map(fn ($i) => ['wp_post_id' => $i, 'views' => 10, 'contact_rate' => 5, 'engagement' => 2], range(1, 100));
        $page2 = array_map(fn ($i) => ['wp_post_id' => $i, 'views' => 10, 'contact_rate' => 5, 'engagement' => 2], range(101, 150));

        $mockWp = $this->createMock(WpSyncService::class);
        $mockWp->expects($this->exactly(2))
            ->method('getAnalyticsRankings')
            ->willReturnOnConsecutiveCalls(
                ['data' => $page1],
                ['data' => $page2],
            );

        $service = new AutoOptimizeMarketStats($mockWp);
        Cache::flush();

        $result = $service->forPlatform(1, ['from' => '2026-05-01', 'to' => '2026-06-01']);

        $this->assertSame(150, $result['sampleSize']);
    }

    public function test_returns_zero_averages_for_empty_response(): void
    {
        $mockWp = $this->createMock(WpSyncService::class);
        $mockWp->method('getAnalyticsRankings')->willReturn(['data' => []]);

        $service = new AutoOptimizeMarketStats($mockWp);
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
            ->method('getAnalyticsRankings')
            ->willReturn(['data' => $profiles]);

        $service = new AutoOptimizeMarketStats($mockWp);
        Cache::flush();

        $service->forPlatform(1, ['from' => '2026-05-01', 'to' => '2026-06-01']);
        $service->forPlatform(1, ['from' => '2026-05-01', 'to' => '2026-06-01']);
    }
}
