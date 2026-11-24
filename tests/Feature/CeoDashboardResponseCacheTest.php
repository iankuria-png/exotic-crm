<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\CeoDashboardResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CeoDashboardResponseCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['crm.ceo_dashboard_cache.enabled' => true]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_miss_then_hit_serves_identical_json_without_recomputing(): void
    {
        Carbon::setTestNow('2026-11-24 09:00:00');
        [$platform, $product] = $this->market();
        Sanctum::actingAs($this->ceo());
        $this->payment($platform, $product, 40);

        $first = $this->getJson('/api/crm/dashboard/ceo/market-pie?horizon=30d&currency_mode=flat&reporting_currency=USD')
            ->assertOk()->assertHeader('X-Dashboard-Cache', 'miss');

        // A new payment inside the fresh window is not visible yet: the cached body is served.
        $this->payment($platform, $product, 60);
        $second = $this->getJson('/api/crm/dashboard/ceo/market-pie?horizon=30d&currency_mode=flat&reporting_currency=USD')
            ->assertOk()->assertHeader('X-Dashboard-Cache', 'hit');

        $this->assertSame($first->getContent(), $second->getContent());
    }

    public function test_stale_entry_is_served_immediately_then_refreshed_after_the_response(): void
    {
        Carbon::setTestNow('2026-11-24 09:00:00');
        [$platform, $product] = $this->market();
        Sanctum::actingAs($this->ceo());
        $this->payment($platform, $product, 40);
        $url = '/api/crm/dashboard/ceo/market-pie?horizon=30d&currency_mode=flat&reporting_currency=USD';

        $original = $this->getJson($url)->assertHeader('X-Dashboard-Cache', 'miss')->getContent();
        $this->payment($platform, $product, 60);

        Carbon::setTestNow('2026-11-24 09:03:00'); // past the 120s fresh lifetime
        $stale = $this->getJson($url)->assertOk()->assertHeader('X-Dashboard-Cache', 'stale');
        $this->assertSame($original, $stale->getContent());
        $this->assertSame('2026-11-24T09:00:00+00:00', $stale->headers->get('X-Dashboard-Generated-At'));

        // The terminate-phase refresh recomputed the entry, so the next read is fresh and new.
        $refreshed = $this->getJson($url)->assertOk()->assertHeader('X-Dashboard-Cache', 'hit');
        $this->assertNotSame($original, $refreshed->getContent());
        $this->assertSame('2026-11-24T09:03:00+00:00', $refreshed->headers->get('X-Dashboard-Generated-At'));
    }

    public function test_cache_is_scoped_per_user_and_query(): void
    {
        Carbon::setTestNow('2026-11-24 09:00:00');
        [$platform, $product] = $this->market();
        $this->payment($platform, $product, 40);

        Sanctum::actingAs($this->ceo());
        $this->getJson('/api/crm/dashboard/ceo/summary?horizon=30d')->assertHeader('X-Dashboard-Cache', 'miss');
        $this->getJson('/api/crm/dashboard/ceo/summary?horizon=7d')->assertHeader('X-Dashboard-Cache', 'miss');
        $this->getJson('/api/crm/dashboard/ceo/summary?horizon=30d')->assertHeader('X-Dashboard-Cache', 'hit');

        Sanctum::actingAs($this->ceo());
        $this->getJson('/api/crm/dashboard/ceo/summary?horizon=30d')->assertHeader('X-Dashboard-Cache', 'miss');
    }

    public function test_access_gate_and_validation_errors_are_not_cached(): void
    {
        Sanctum::actingAs($this->user(['role' => 'admin', 'is_ceo' => false]));
        $this->getJson('/api/crm/dashboard/ceo/summary')->assertForbidden()->assertHeaderMissing('X-Dashboard-Cache');

        Sanctum::actingAs($this->ceo());
        $this->getJson('/api/crm/dashboard/ceo/product-revenue?product=bogus')->assertUnprocessable()->assertHeaderMissing('X-Dashboard-Cache');
        $this->getJson('/api/crm/dashboard/ceo/product-revenue?product=bogus')->assertUnprocessable();
    }

    public function test_disabled_cache_computes_every_request(): void
    {
        config(['crm.ceo_dashboard_cache.enabled' => false]);
        Sanctum::actingAs($this->ceo());

        $this->getJson('/api/crm/dashboard/ceo/summary?horizon=30d')->assertOk()->assertHeaderMissing('X-Dashboard-Cache');
    }

    public function test_warm_command_fills_the_exact_keys_the_dashboard_requests_on_first_load(): void
    {
        Carbon::setTestNow('2026-11-24 09:00:00');
        [$platform, $product] = $this->market();
        $this->payment($platform, $product, 40);
        $ceo = $this->ceo();
        $this->user(['role' => 'admin', 'is_ceo' => true, 'status' => 'inactive']);

        $this->artisan('crm:warm-ceo-dashboard')->expectsOutputToContain('Warmed CEO dashboard for 1 user(s).')->assertSuccessful();

        Sanctum::actingAs($ceo);
        $base = 'horizon=30d&currency_mode=flat&reporting_currency=USD';
        foreach ([
            "/api/crm/dashboard/ceo/summary?{$base}",
            "/api/crm/dashboard/ceo/product-revenue?{$base}&analysis=1&product=all&bucket=auto&analysis_view=trend",
            "/api/crm/dashboard/ceo/market-pie?{$base}",
            "/api/crm/dashboard/ceo/agent-performance?{$base}",
            "/api/crm/dashboard/ceo/recent-payments?{$base}&limit=10&channel=all",
        ] as $url) {
            $this->getJson($url)->assertOk()->assertHeader('X-Dashboard-Cache', 'hit');
        }
    }

    public function test_cached_body_matches_uncached_body(): void
    {
        Carbon::setTestNow('2026-11-24 09:00:00');
        [$platform, $product] = $this->market();
        $this->payment($platform, $product, 40);
        Sanctum::actingAs($this->ceo());
        $url = '/api/crm/dashboard/ceo/product-revenue?horizon=30d&currency_mode=flat&reporting_currency=USD&analysis=1&product=all&bucket=auto&analysis_view=trend';

        config(['crm.ceo_dashboard_cache.enabled' => false]);
        $uncached = $this->getJson($url)->assertOk()->getContent();
        config(['crm.ceo_dashboard_cache.enabled' => true]);
        $this->getJson($url)->assertHeader('X-Dashboard-Cache', 'miss');
        $cached = $this->getJson($url)->assertHeader('X-Dashboard-Cache', 'hit')->getContent();

        $this->assertSame($uncached, $cached);
        $this->assertArrayHasKey('collections', json_decode($cached, true));
    }

    public function test_key_normalises_parameter_types_and_order(): void
    {
        $user = $this->ceo();
        $cache = app(CeoDashboardResponseCache::class);
        $a = \Illuminate\Http\Request::create('/x', 'GET', ['analysis' => 1, 'horizon' => '30d']);
        $a->setUserResolver(fn () => $user);
        $b = \Illuminate\Http\Request::create('/x?horizon=30d&analysis=1', 'GET');
        $b->setUserResolver(fn () => $user);

        $this->assertSame($cache->key($a, 'summary'), $cache->key($b, 'summary'));
    }

    private function market(): array
    {
        $platform = Platform::factory()->create(['name' => 'Nairobi', 'country' => 'Kenya', 'currency_code' => 'USD']);
        $product = Product::factory()->create(['platform_id' => $platform->id, 'currency' => 'USD']);

        return [$platform, $product];
    }

    private function ceo(): User
    {
        return $this->user(['role' => 'admin', 'is_ceo' => true, 'status' => 'active']);
    }

    private function user(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'Test User',
            'email' => Str::uuid().'@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
            'is_ceo' => false,
            'assigned_market_ids' => [],
        ], $overrides));
    }

    private function payment(Platform $platform, Product $product, float $amount): Payment
    {
        $payment = Payment::factory()->make([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'amount' => $amount,
            'currency' => 'USD',
            'status' => 'completed',
            'purpose' => 'subscription',
            'provider_environment' => null,
            'record_classification' => Payment::RECORD_CLASSIFICATION_LIVE,
            'reconciliation_state' => 'open',
            'resolution_code' => null,
            'source' => 'gateway',
            'completed_at' => now()->subHour(),
            'created_at' => now()->subHour(),
        ]);
        $payment->save();

        return $payment;
    }
}
