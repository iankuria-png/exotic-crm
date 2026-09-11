<?php

namespace Tests\Feature\Forecast;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Services\ClientFunnelService;
use App\Services\Forecast\ForecastBaselineService;
use App\Services\Forecast\ForecastContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The renewal count must not change when the query shape does.
 *
 * renewalStats() used to join a payments sub-select on
 * `client_id = deals.client_id OR deal_id = deals.id`, with COALESCE'd dates
 * compared against DATE_SUB/DATE_ADD(deals.expires_at). No index can serve any
 * of that, so it compared every eligible deal against every reportable payment
 * — 20.9s in production. It is now two EXISTS branches, each able to seek an
 * index.
 *
 * These tests run the original join alongside the shipped implementation on the
 * same rows and require the same answer, so the rewrite is pinned to the
 * behaviour it replaced rather than to a number someone typed in.
 */
class RenewalRateQueryShapeTest extends TestCase
{
    use RefreshDatabase;

    private Platform $platform;

    private \App\Models\Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->platform = Platform::factory()->create(['phone_prefix' => '254']);
        // One product for every fixture: PaymentFactory would otherwise mint a
        // new one per payment and exhaust Faker's unique() pool.
        $this->product = \App\Models\Product::factory()->create(['platform_id' => $this->platform->id]);
    }

    private function context(): ForecastContext
    {
        return new ForecastContext(
            from: Carbon::parse('2026-06-01')->startOfDay(),
            to: Carbon::parse('2026-09-30')->endOfDay(),
            platformId: null,
            platformScope: [$this->platform->id],
            accessiblePlatformIds: [$this->platform->id],
            currency: 'USD',
            days: 120,
            mode: 'project',
            horizonDays: 90,
        );
    }

    private function deal(Client $client, string $expiresAt, array $overrides = []): Deal
    {
        return Deal::factory()->create(array_merge([
            'platform_id' => $this->platform->id,
            'client_id' => $client->id,
            'status' => 'expired',
            'is_free_trial' => false,
            'amount' => 1000,
            'product_id' => $this->product->id,
            'expires_at' => Carbon::parse($expiresAt),
        ], $overrides));
    }

    private function payment(array $overrides = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'platform_id' => $this->platform->id,
            'status' => 'completed',
            'subscription_lifecycle' => 'renewal',
            'amount' => 1000,
            'product_id' => $this->product->id,
        ], $overrides));
    }

    /**
     * The implementation as it was before the rewrite, kept here as the oracle.
     */
    private function renewedViaOriginalJoin(): int
    {
        $settleDays = (int) config('forecast.settle_days');
        $earlyDays = (int) config('forecast.renewal_early_days', 7);
        $paidAt = 'COALESCE(payments.completed_at, payments.created_at)';
        $sqlite = \DB::connection()->getDriverName() === 'sqlite';
        $open = $sqlite
            ? "datetime(deals.expires_at, '-{$earlyDays} days')"
            : "DATE_SUB(deals.expires_at, INTERVAL {$earlyDays} DAY)";
        $close = $sqlite
            ? "datetime(deals.expires_at, '+{$settleDays} days')"
            : "DATE_ADD(deals.expires_at, INTERVAL {$settleDays} DAY)";

        return (int) Deal::query()
            ->whereBetween('deals.expires_at', [$this->context()->from, $this->context()->to])
            ->whereIn('deals.status', ClientFunnelService::PAID_DEAL_STATUSES)
            ->where(fn (Builder $q) => $q->whereNull('deals.is_free_trial')->orWhere('deals.is_free_trial', false))
            ->where('deals.amount', '>', 0)
            ->where('deals.platform_id', $this->platform->id)
            ->joinSub(
                Payment::query()->reportableSuccessful()->excludingWalletTopups()
                    ->select(['payments.id', 'payments.client_id', 'payments.deal_id', 'payments.subscription_lifecycle', 'payments.completed_at', 'payments.created_at', 'payments.amount', 'payments.currency', 'payments.platform_id']),
                'payments',
                function ($join) use ($paidAt, $open, $close) {
                    $join->on(function ($identity) {
                        $identity->on('payments.client_id', '=', 'deals.client_id')
                            ->orOn('payments.deal_id', '=', 'deals.id');
                    })
                        ->whereRaw("{$paidAt} >= {$open}")
                        ->whereRaw("{$paidAt} <= {$close}")
                        ->where('payments.subscription_lifecycle', '=', 'renewal');
                }
            )
            ->distinct()
            ->count('deals.id');
    }

    private function shippedRenewed(): int
    {
        $stats = app(ForecastBaselineService::class)->build($this->context());

        return (int) ($stats['levers']['renewal']['evidence']['renewed'] ?? -1);
    }

    public function test_both_links_and_both_window_edges_agree_with_the_original_join(): void
    {
        // Linked by client_id only — the first EXISTS branch.
        $a = Client::factory()->create(['platform_id' => $this->platform->id, 'phone_normalized' => '254700000001']);
        $dealA = $this->deal($a, '2026-08-10');
        $this->payment(['client_id' => $a->id, 'deal_id' => null, 'completed_at' => Carbon::parse('2026-08-12')]);

        // Linked by deal_id only, with a null client_id — the second branch,
        // and the reason the OR existed at all.
        $b = Client::factory()->create(['platform_id' => $this->platform->id, 'phone_normalized' => '254700000002']);
        $dealB = $this->deal($b, '2026-08-14');
        $this->payment(['client_id' => null, 'deal_id' => $dealB->id, 'completed_at' => Carbon::parse('2026-08-15')]);

        // Two qualifying payments for one deal: EXISTS must count it once,
        // which is what distinct() used to do for the join.
        $c = Client::factory()->create(['platform_id' => $this->platform->id, 'phone_normalized' => '254700000003']);
        $dealC = $this->deal($c, '2026-08-20');
        $this->payment(['client_id' => $c->id, 'completed_at' => Carbon::parse('2026-08-21')]);
        $this->payment(['client_id' => $c->id, 'completed_at' => Carbon::parse('2026-08-22')]);

        // Outside the window on both edges — must not count.
        $d = Client::factory()->create(['platform_id' => $this->platform->id, 'phone_normalized' => '254700000004']);
        $this->deal($d, '2026-08-20');
        $this->payment(['client_id' => $d->id, 'completed_at' => Carbon::parse('2026-07-01')]);

        $e = Client::factory()->create(['platform_id' => $this->platform->id, 'phone_normalized' => '254700000005']);
        $this->deal($e, '2026-08-20');
        $this->payment(['client_id' => $e->id, 'completed_at' => Carbon::parse('2026-09-28')]);

        $original = $this->renewedViaOriginalJoin();

        $this->assertSame(3, $original, 'oracle sanity: three deals renew inside the window');
        $this->assertSame(
            $original,
            $this->shippedRenewed(),
            'the rewrite must return exactly what the join returned'
        );
    }

    public function test_excluded_payment_kinds_still_do_not_count(): void
    {
        // reportableSuccessful() and excludingWalletTopups() must survive the
        // rewrite — dropping them would silently inflate every renewal rate.
        $client = Client::factory()->create(['platform_id' => $this->platform->id, 'phone_normalized' => '254700000010']);
        $this->deal($client, '2026-08-10');

        $this->payment(['client_id' => $client->id, 'completed_at' => Carbon::parse('2026-08-11'), 'status' => 'failed']);
        $this->payment(['client_id' => $client->id, 'completed_at' => Carbon::parse('2026-08-11'), 'purpose' => Payment::PURPOSE_WALLET_TOPUP]);
        $this->payment(['client_id' => $client->id, 'completed_at' => Carbon::parse('2026-08-11'), 'subscription_lifecycle' => 'new']);

        $original = $this->renewedViaOriginalJoin();

        $this->assertSame(0, $original, 'oracle sanity: none of these are reportable renewals');
        $this->assertSame($original, $this->shippedRenewed());
    }

    public function test_the_query_is_two_exists_branches_and_not_a_join(): void
    {
        // The answer being right is not enough: a join returns the right answer
        // too, it just takes 20 seconds. This pins the shape so a future
        // refactor cannot quietly reintroduce the unindexable version.
        \DB::enableQueryLog();

        $client = Client::factory()->create(['platform_id' => $this->platform->id, 'phone_normalized' => '254700000020']);
        $this->deal($client, '2026-08-10');
        $this->payment(['client_id' => $client->id, 'completed_at' => Carbon::parse('2026-08-11')]);

        $this->shippedRenewed();

        $renewalCount = collect(\DB::getQueryLog())
            ->pluck('query')
            ->first(fn (string $q) => str_contains($q, 'subscription_lifecycle')
                && str_contains($q, 'count(')
                && str_contains($q, 'deals'));

        \DB::disableQueryLog();

        $this->assertNotNull($renewalCount, 'the renewal count query should have run');

        // Each link is its own correlated EXISTS, so each can seek an index:
        // payments_forecast_client_lifecycle_idx and payments_deal_status_idx.
        // (There are more `exists` than two in the SQL — reportableSuccessful()
        // adds a NOT EXISTS bundle check inside each branch — so assert on the
        // correlation predicates, which are the part that matters.)
        // The suite runs SQLite (double quotes), production MariaDB (backticks).
        $sql = str_replace(['`', '"'], '', $renewalCount);

        $this->assertStringContainsString('payments.client_id = deals.client_id', $sql);
        $this->assertStringContainsString('payments.deal_id = deals.id', $sql);
        $this->assertStringNotContainsString(
            'inner join',
            $sql,
            'the OR-join had no usable access path and must not come back'
        );
        $this->assertStringNotContainsString(
            'distinct',
            $sql,
            'EXISTS matches each deal once, so the join-era distinct is not needed'
        );
    }

    public function test_an_empty_window_agrees_too(): void
    {
        $original = $this->renewedViaOriginalJoin();

        $this->assertSame(0, $original);
        $this->assertSame($original, $this->shippedRenewed());
    }
}
