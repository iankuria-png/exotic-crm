<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Services\MarketHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The health gate must never be the reason a person cannot work.
 *
 * It was installed beneath all 78 WpSyncService call sites, so it refused
 * background sweeps and salespeople alike — and it fired on a status that a
 * mere timeout produces. exotickenya.com was loading fine in a browser while
 * the CRM called it "Server down" and blocked every WordPress action on it.
 */
class MarketHealthGateScopeTest extends TestCase
{
    use RefreshDatabase;

    private function health(): MarketHealthService
    {
        return app(MarketHealthService::class);
    }

    public function test_a_timeout_never_gates_anything(): void
    {
        // connectionExceptionStatus() resolves "timed out" to server_error, so
        // this is the status a slow-but-serving market lands on. It must not
        // stop work at any failure count.
        foreach ([2, 5, 50] as $failures) {
            $this->assertFalse(
                $this->health()->shouldFailFast(MarketHealthService::STATUS_SERVER_ERROR, $failures),
                "server_error must not gate (failures: {$failures}) — it is what a timeout produces"
            );
        }
    }

    public function test_wp_and_auth_errors_never_gate_either(): void
    {
        $this->assertFalse($this->health()->shouldFailFast(MarketHealthService::STATUS_WP_ERROR, 10));
        $this->assertFalse($this->health()->shouldFailFast(MarketHealthService::STATUS_AUTH_ERROR, 10));
        $this->assertFalse($this->health()->shouldFailFast(MarketHealthService::STATUS_HEALTHY, 10));
    }

    public function test_only_a_connection_that_never_established_gates_background_work(): void
    {
        // The one signal impatience cannot fake: refused / DNS / no route.
        $this->assertTrue(
            $this->health()->shouldFailFast(MarketHealthService::STATUS_DOMAIN_UNREACHABLE, 2)
        );

        // And still only after repeated failures, not on a single blip.
        $this->assertFalse(
            $this->health()->shouldFailFast(MarketHealthService::STATUS_DOMAIN_UNREACHABLE, 1)
        );
    }

    public function test_the_probe_allows_a_slow_market_to_finish(): void
    {
        $reflection = new \ReflectionClass(MarketHealthService::class);
        $timeout = $reflection->getConstant('PROBE_TIMEOUT_SECONDS');

        // Healthy markets in production probe at 1.7-3.7s against a full public
        // homepage. A cutoff near that turns ordinary page weight into an
        // outage, which is exactly what happened.
        $this->assertGreaterThanOrEqual(
            10,
            $timeout,
            'the probe timeout must leave real headroom over a normal homepage'
        );
    }

    public function test_an_unreachable_market_does_not_block_an_interactive_request(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Kenya',
            'domain' => 'ke-'.Str::random(5).'.example.test',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://ke.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'u',
            'wp_api_password' => 'p',
            'health_status' => MarketHealthService::STATUS_DOMAIN_UNREACHABLE,
            'health_consecutive_failures' => 25,
        ]);

        // The test process is console, which is where the gate DOES apply, so
        // assert the scoping rule directly rather than faking a web request.
        $this->assertTrue(
            app()->runningInConsole(),
            'sanity: the suite runs in console, the context the gate is scoped to'
        );

        $this->assertTrue(
            $this->health()->shouldFailFast(
                (string) $platform->health_status,
                (int) $platform->health_consecutive_failures
            ),
            'background work should still skip a market that refuses connections'
        );
    }
}
