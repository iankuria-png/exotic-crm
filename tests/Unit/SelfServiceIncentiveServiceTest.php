<?php

namespace Tests\Unit;

use App\Models\BillingSubscriptionRule;
use App\Models\Platform;
use App\Services\SelfServiceIncentiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelfServiceIncentiveServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_window_returns_percent(): void
    {
        $platform = Platform::factory()->create();

        BillingSubscriptionRule::query()->create([
            'market_id' => $platform->id,
            'discount_json' => [
                'self_service_incentive' => [
                    'enabled' => true,
                    'percent' => 10,
                    'starts_at' => now()->subHour()->toIso8601String(),
                    'expires_at' => now()->addHour()->toIso8601String(),
                    'sources' => ['wallet', 'self_checkout'],
                ],
            ],
        ]);

        $this->assertSame(10.0, app(SelfServiceIncentiveService::class)->resolveForPlatform($platform->id, 'wallet'));
    }

    public function test_before_start_returns_null(): void
    {
        $platform = Platform::factory()->create();

        BillingSubscriptionRule::query()->create([
            'market_id' => $platform->id,
            'discount_json' => [
                'self_service_incentive' => [
                    'enabled' => true,
                    'percent' => 10,
                    'starts_at' => now()->addHour()->toIso8601String(),
                    'expires_at' => now()->addDay()->toIso8601String(),
                    'sources' => ['wallet'],
                ],
            ],
        ]);

        $this->assertNull(app(SelfServiceIncentiveService::class)->resolveForPlatform($platform->id, 'wallet'));
    }

    public function test_after_expiry_returns_null(): void
    {
        $platform = Platform::factory()->create();

        BillingSubscriptionRule::query()->create([
            'market_id' => $platform->id,
            'discount_json' => [
                'self_service_incentive' => [
                    'enabled' => true,
                    'percent' => 10,
                    'starts_at' => now()->subDay()->toIso8601String(),
                    'expires_at' => now()->subMinute()->toIso8601String(),
                    'sources' => ['wallet'],
                ],
            ],
        ]);

        $this->assertNull(app(SelfServiceIncentiveService::class)->resolveForPlatform($platform->id, 'wallet'));
    }

    public function test_excluded_source_returns_null(): void
    {
        $platform = Platform::factory()->create();

        BillingSubscriptionRule::query()->create([
            'market_id' => $platform->id,
            'discount_json' => [
                'self_service_incentive' => [
                    'enabled' => true,
                    'percent' => 10,
                    'starts_at' => now()->subHour()->toIso8601String(),
                    'expires_at' => now()->addHour()->toIso8601String(),
                    'sources' => ['wallet'],
                ],
            ],
        ]);

        $this->assertNull(app(SelfServiceIncentiveService::class)->resolveForPlatform($platform->id, 'manual_submission'));
    }
}
