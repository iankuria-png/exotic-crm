<?php

namespace Tests\Feature\Forecast;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Services\Forecast\ForecastBaselineService;
use App\Services\Forecast\ForecastContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * These two guard the failures that shipped once already: the claim resolver
 * existing but never being called, and per-market baselines re-running the full
 * recovery pass once per market. Both are invisible to tests that mock the
 * baseline service, so these exercise the real build().
 */
class ForecastBaselineWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_claims_an_overlapping_client_once_by_precedence(): void
    {
        $platform = Platform::factory()->create(['phone_prefix' => '254']);

        // One person who qualifies for recovery, renewal and win-back at once.
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'phone_normalized' => '254712345678',
            'churned_at' => Carbon::parse('2026-08-20'),
        ]);

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'phone' => '0712345678',
            'status' => 'failed',
            'created_at' => Carbon::parse('2026-08-18'),
        ]);

        Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'expired',
            'is_free_trial' => false,
            'amount' => 25,
            'expires_at' => Carbon::parse('2026-08-25'),
        ]);

        $baseline = app(ForecastBaselineService::class)->build($this->context([$platform->id]));

        $reconciliation = $baseline['identity_reconciliation'] ?? null;
        $this->assertIsArray($reconciliation, 'build() must run identity and claim resolution, not skip it.');

        $this->assertSame(1, (int) ($reconciliation['claimed']['failed_recovery'] ?? 0));

        $excluded = (int) ($reconciliation['excluded_by_precedence']['renewal'] ?? 0)
            + (int) ($reconciliation['excluded_by_precedence']['churn_winback'] ?? 0);

        $this->assertGreaterThanOrEqual(
            1,
            $excluded,
            'The same client was claimed by more than one lever - contributions are double counted.'
        );
    }

    public function test_baseline_work_does_not_scale_with_market_count(): void
    {
        Platform::factory()->count(2)->create(['phone_prefix' => '254']);
        $twoMarkets = $this->countBuildQueries();

        Platform::factory()->count(6)->create(['phone_prefix' => '254']);
        $eightMarkets = $this->countBuildQueries();

        $this->assertLessThanOrEqual(
            $twoMarkets + 6,
            $eightMarkets,
            "Query count grew from {$twoMarkets} to {$eightMarkets} when six markets were added - "
            .'per-market baselines are re-running full recovery passes instead of partitioning one.'
        );
    }

    private function countBuildQueries(): int
    {
        $count = 0;
        DB::flushQueryLog();
        DB::listen(function () use (&$count) {
            $count++;
        });

        app(ForecastBaselineService::class)->build($this->context());

        return $count;
    }

    private function context(?array $platformIds = null): ForecastContext
    {
        return new ForecastContext(
            from: Carbon::parse('2026-08-12')->startOfDay(),
            to: Carbon::parse('2026-09-10')->endOfDay(),
            platformId: null,
            platformScope: $platformIds,
            accessiblePlatformIds: $platformIds,
            currency: 'USD',
            days: 30,
            mode: 'project',
            horizonDays: 90,
        );
    }
}
