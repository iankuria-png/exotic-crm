<?php

namespace Tests\Feature\FlexibleAmounts;

use App\Services\FlexibleAmounts\FlexibleSelfCheckoutService;
use App\Services\WalletSettingsService;
use App\Services\WpSyncFactory;
use App\Services\WpSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FlexibleDashboardReadTest extends TestCase
{
    use RefreshDatabase, \Tests\Support\FlexibleAmountsFixture;

    private $authSettings;

    private function envelope(array $changes = []): array
    {
        return array_replace($this->state, ['platform_id' => (int) $this->market->id, 'issued_at' => now()->timestamp], $changes);
    }

    private function sendRead(string $action, array $input, bool $signed = true)
    {
        if (! $this->authSettings) {
            $this->authSettings = \Mockery::mock(app(WalletSettingsService::class));
            $this->authSettings->shouldReceive('verifyWpToCrmBearer')->andReturn(true);
            $this->authSettings->shouldReceive('wpToCrmHmacSecret')->andReturn('fixture-hmac');
            $this->app->instance(WalletSettingsService::class, $this->authSettings);
        }
        $path = '/api/wp-svc/flexible-amounts/'.$action;
        $body = json_encode($input + ['wp_user_id' => $this->client->wp_user_id, 'authenticated_customer' => true]);
        $key = (string) Str::uuid(); $timestamp = (string) now()->timestamp;
        $sig = hash_hmac('sha256', implode("\n", [$timestamp, 'POST', $path, (string) $this->market->id, $key, hash('sha256', $body)]), 'fixture-hmac');

        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer fixture-bearer', 'HTTP_X_EXOTIC_PLATFORM_ID' => $this->market->id,
            'HTTP_X_EXOTIC_TIMESTAMP' => $timestamp, 'HTTP_X_IDEMPOTENCY_KEY' => $key, 'HTTP_X_EXOTIC_SIGNATURE' => $signed ? $sig : 'wrong'], $body);
    }

    public function test_signed_native_snapshot_supplies_methods_previews_and_quote_without_a_callback(): void
    {
        $this->wp->shouldNotReceive('payLinkState');
        $this->wp->shouldNotReceive('payLinkStateForRead');
        $response = $this->sendRead('catalogue', ['wp_subscription_state' => $this->envelope()])->assertOk();
        $this->assertContains('pawapay', array_column($response->json('payment_methods'), 'key'));
        $this->assertCount(88, $response->json('plans.0.preview.rows'));
        $this->sendRead('quote', ['product_id' => $this->product->id, 'amount' => '1000', 'wp_subscription_state' => $this->envelope()])
            ->assertOk()->assertJsonPath('days', 4)->assertJsonPath('charge', '880.00');
        Http::assertNothingSent();
    }

    public function test_forged_or_foreign_stale_and_badly_typed_snapshots_are_rejected(): void
    {
        $this->sendRead('catalogue', ['wp_subscription_state' => $this->envelope()], false)->assertUnauthorized();
        foreach ([['platform_id' => $this->market->id + 1], ['post_id' => $this->client->wp_post_id + 1],
            ['wp_user_id' => $this->client->wp_user_id + 1], ['issued_at' => now()->timestamp - 31], ['issued_at' => now()->timestamp + 6],
            ['issued_at' => (string) now()->timestamp], ['live' => 'false'], ['eligible' => 1], ['writers_ready' => false],
            ['version' => '1'], ['current_tier' => 'staff'], ['escort_expire' => -1], ['escort_expire' => '9000']] as $changes) {
            $this->sendRead('catalogue', ['wp_subscription_state' => $this->envelope($changes)])->assertStatus(409);
        }
        $this->sendRead('catalogue', ['wp_subscription_state' => 'arbitrary'])->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_signed_snapshot_does_not_override_crm_lifecycle_holds(): void
    {
        $this->client->update(['profile_paused_at' => now()]);
        $this->sendRead('catalogue', ['wp_subscription_state' => $this->envelope()])->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_old_proxy_uses_one_bounded_get_and_retains_http_conflict_status(): void
    {
        config(['services.exotic_crm_sync.health_gate_enabled' => false]);
        $this->market->update(['wp_api_url' => 'https://native-wp.test/wp-json/exotic-crm-sync/v1', 'wp_api_user' => 'fixture', 'wp_api_password' => 'fixture']);
        $real = new WpSyncService($this->market);
        $this->app->instance(WpSyncFactory::class, \Mockery::mock(WpSyncFactory::class)->shouldReceive('forPlatform')->andReturn($real)->getMock());
        Http::fake(['*' => Http::response(['code' => 'subscription_busy', 'message' => 'Private upstream details'], 409)]);
        $this->sendRead('catalogue', [])->assertStatus(409)->assertJsonPath('message', 'This profile needs a team check before renewing.');
        Http::assertSentCount(1);
    }

    public function test_old_proxy_upstream_failure_is_an_actionable_503_without_retry(): void
    {
        config(['services.exotic_crm_sync.health_gate_enabled' => false]);
        $real = new WpSyncService($this->market);
        $this->app->instance(WpSyncFactory::class, \Mockery::mock(WpSyncFactory::class)->shouldReceive('forPlatform')->andReturn($real)->getMock());
        Http::fake(['*' => Http::response(['message' => 'Private upstream details'], 503)]);
        $this->sendRead('quote', ['product_id' => $this->product->id, 'amount' => '1000'])->assertStatus(503)
            ->assertJsonPath('message', 'Could not check your profile. Please try again.');
        Http::assertSentCount(1);
    }

    public function test_old_proxy_connection_failure_is_503_and_checkout_ignores_the_read_envelope(): void
    {
        config(['services.exotic_crm_sync.health_gate_enabled' => false]);
        $real = new WpSyncService($this->market);
        $this->app->instance(WpSyncFactory::class, \Mockery::mock(WpSyncFactory::class)->shouldReceive('forPlatform')->andReturn($real)->getMock());
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('fixture timeout'));
        $this->sendRead('catalogue', [])->assertStatus(503);
        Http::assertSentCount(0);
        // A supplied read envelope never replaces the fresh native state at reservation.
        $this->app->instance(WpSyncFactory::class, \Mockery::mock(WpSyncFactory::class)->shouldReceive('forPlatform')->andReturn($this->wp)->getMock());
        $request = $this->request(['wp_subscription_state' => $this->envelope()]);
        $this->state['eligible'] = false;
        try {
            app(FlexibleSelfCheckoutService::class)->start($request);
            $this->fail('Checkout must recheck native eligibility.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }
}
