<?php

namespace Tests\Feature\Forecast;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Services\Forecast\ForecastBaselineService;
use App\Services\Forecast\ForecastContext;
use App\Services\Forecast\SignupSourceConversionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * These guard the failures that shipped once already: the claim resolver
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

    public function test_baseline_accepts_the_string_boolean_a_query_string_actually_sends(): void
    {
        // The widget sends cache_only as a GET param, so it arrives as the string
        // "true". Laravel's boolean rule rejects that, which 422'd every widget
        // request in production before any forecast code ran.
        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'is_ceo' => true,
        ]));

        $this->getJson('/api/crm/dashboard/ceo/forecast/baseline'
            .'?from=2026-08-12&to=2026-09-10&currency=USD&horizon_days=90&cache_only=true')
            ->assertOk()
            ->assertJsonPath('state', 'cold');
    }

    public function test_signup_source_conversion_never_correlates_a_subquery_inside_a_grouped_select(): void
    {
        // SQLite does not enforce ONLY_FULL_GROUP_BY, so the rule itself cannot be
        // asserted here - but the shape that violates it can. A correlated subquery
        // on clients.platform_id inside a grouped select is a 1055 on prod.
        $platform = Platform::factory()->create(['phone_prefix' => '254']);
        Client::factory()->create(['platform_id' => $platform->id]);

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = strtolower($query->sql);
        });

        app(SignupSourceConversionService::class)->summarize(
            Carbon::parse('2026-08-12')->startOfDay(),
            Carbon::parse('2026-09-10')->endOfDay(),
            [$platform->id],
            'USD'
        );

        // Identifier quoting differs between raw and builder-generated SQL, so compare
        // on a normalised form or the assertion silently matches nothing.
        $normalised = array_map(
            fn (string $sql) => str_replace(['"', '`', '[', ']'], '', $sql),
            $statements
        );
        $grouped = array_values(array_filter($normalised, fn (string $sql) => str_contains($sql, 'group by')));
        $this->assertNotEmpty($grouped, 'Expected the conversion query to be grouped.');

        foreach ($grouped as $sql) {
            $this->assertStringNotContainsString(
                'from platforms where',
                $sql,
                'A grouped select correlates a subquery on clients.platform_id - MySQL rejects this with 1055.'
            );

            // MySQL matches GROUP BY to SELECT on the parse tree, and two separate `?`
            // markers are not provably equal - so a placeholder inside GROUP BY makes
            // the bare column read as ungrouped. Group by the select alias instead.
            // strrpos, not strpos: the first 'group by' belongs to the joined subquery.
            $groupBy = substr($sql, strrpos($sql, 'group by'));
            $this->assertStringNotContainsString(
                '?',
                $groupBy,
                'GROUP BY carries a bind placeholder - MariaDB cannot match it to the SELECT expression.'
            );

            // MariaDB, unlike MySQL, does not resolve a GROUP BY alias back to its
            // expression for the ONLY_FULL_GROUP_BY check - it binds the name to a real
            // column and then reports every other column in the expression as ungrouped.
            // The expression has to be repeated, with its literal inlined.
            $this->assertStringContainsString(
                'coalesce(',
                $groupBy,
                'GROUP BY uses bare aliases - MariaDB resolves those to columns and rejects the select with 1055.'
            );
        }
    }

    public function test_claim_precedence_never_makes_a_rate_exceed_its_own_denominator(): void
    {
        // Claims are counted per person; renewal counts expiring subscriptions, of which
        // one client can hold several. Replacing the denominator with a headcount made
        // renewal read 2,769 of 2,102 - 131%, clamped to a meaningless 100.
        $service = app(ForecastBaselineService::class);
        $apply = new \ReflectionMethod($service, 'applyClaimStats');
        $apply->setAccessible(true);

        $levers = [
            'renewal' => [
                'key' => 'renewal',
                'unit' => 'percentage_points',
                'actual' => 75.6,
                'eligible_units' => 3661,
                'evidence' => ['eligible' => 3661, 'renewed' => 2768],
            ],
        ];

        $result = $apply->invoke($service, $levers, [
            'sets' => ['renewal' => array_fill(0, 2102, 'root')],
            'excluded' => ['renewal' => 199],
        ]);

        // Claim sets count people; levers count subscriptions or signups. Folding one
        // into the other broke this twice - once inflating a rate past 100%, once
        // shrinking a lever's value twentyfold. Claim resolution reports, it does not
        // restate.
        $this->assertSame(75.6, $result['renewal']['actual'], 'A measured rate must survive claim resolution unchanged.');
        $this->assertSame(3661, (int) $result['renewal']['eligible_units'], 'A measured denominator must survive claim resolution unchanged.');
        $this->assertSame(199, (int) $result['renewal']['evidence']['also_claimed_by_another_lever'], 'Overlap is reported as evidence.');
        $this->assertGreaterThanOrEqual(
            (int) $result['renewal']['evidence']['renewed'],
            (int) $result['renewal']['eligible_units'],
            'The renewed count must never exceed the denominator it was measured against.'
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
