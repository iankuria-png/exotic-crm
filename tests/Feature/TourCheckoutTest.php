<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\TourMarketSetting;
use App\Models\TourOrder;
use App\Models\TourPaymentAttempt;
use App\Models\WalletTransaction;
use App\Services\BillingGatewayService;
use App\Services\BillingModeService;
use App\Services\Monetization\ListingEligibility;
use App\Services\PaymentCompletionService;
use App\Services\Tours\CheckoutService;
use App\Services\Tours\DistributionService;
use App\Services\Tours\PricingService;
use App\Services\TourSettingsService;
use App\Services\WalletService;
use App\Services\WalletSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Feature\Kyc\Concerns\InteractsWithKycFixtures;
use Tests\TestCase;

class TourCheckoutTest extends TestCase
{
    use InteractsWithKycFixtures,RefreshDatabase;

    private Platform $home;

    private Platform $destination;

    private Client $owner;

    private array $snapshot;

    private array $settings;

    private array $remote = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->home = $this->createPlatform(['country' => 'Kenya', 'domain' => 'https://home.example.com', 'currency_code' => 'KES', 'phone_prefix' => '254', 'wp_api_url' => 'https://home.example.com/wp-json/exotic-crm-sync/v1', 'wp_api_user' => 'test', 'wp_api_password' => 'fixture']);
        $this->destination = $this->createPlatform(['domain' => 'https://destination.example.com', 'wp_api_url' => 'https://destination.example.com/wp-json/exotic-crm-sync/v1', 'wp_api_user' => 'test', 'wp_api_password' => 'fixture', 'is_active' => true]);
        $this->owner = $this->createClientForPlatform($this->home, ['wp_post_id' => 123, 'wp_user_id' => 77, 'profile_status' => 'publish', 'escort_expire' => now()->addDays(10)->timestamp]);
        $this->settings = app(TourSettingsService::class)->defaults();
        $this->settings['enabled'] = true;
        $this->settings['environment'] = 'production';
        $this->settings['passes'] = [['code' => 'gulf_week', 'name' => 'Dubai week', 'region' => 'gulf', 'currency' => 'KES', 'amount_minor' => 200000, 'days' => 7, 'market_limit' => null, 'destination_platform_ids' => [$this->destination->id], 'enabled' => true], ['code' => 'gulf_fortnight', 'name' => 'Dubai fortnight', 'region' => 'gulf', 'currency' => 'KES', 'amount_minor' => 400000, 'days' => 14, 'market_limit' => null, 'destination_platform_ids' => [$this->destination->id], 'enabled' => true]];
        TourMarketSetting::create(['platform_id' => $this->home->id, 'settings' => $this->settings, 'revision' => 1]);
        $this->snapshot = ['contract_version' => 1, 'trip_id' => 44, 'generation' => 'g1', 'profile_id' => 123, 'wp_user_id' => 77, 'home_url' => 'https://home.example.com/escort/test', 'home_subscription_end' => now()->addDay()->timestamp, 'restricted' => false, 'purchase_eligible' => true, 'environment' => 'production', 'status' => 'awaiting_payment', 'stops' => [['key' => 'stop1:g1', 'kind' => 'remote', 'region' => 'gulf', 'platform_id' => null, 'city_id' => null, 'start' => now()->addDay()->startOfDay()->timestamp, 'end' => now()->addDays(3)->endOfDay()->timestamp, 'phone' => '', 'whatsapp' => '', 'display_name' => 'Dubai']], 'profile' => ['name' => 'Fixture traveller', 'bio' => 'A local test profile.', 'fields' => ['phone' => '+254700000000']], 'media' => [['source_id' => 7, 'url' => 'https://home.example.com/wp-content/uploads/test.jpg', 'sha256' => str_repeat('a', 64), 'mime' => 'image/jpeg']], 'cover_source_id' => 7];
        $eligibility = Mockery::mock(ListingEligibility::class)->makePartial();
        $eligibility->shouldReceive('facts')->andReturn(['listing_active' => true, 'held' => false]);
        $this->app->instance(ListingEligibility::class, $eligibility);
        $billing = Mockery::mock(BillingModeService::class);
        $billing->shouldReceive('assertWalletAvailable')->andReturn(['environment' => 'production']);
        $billing->shouldReceive('providerContext')->andReturn(['environment' => 'production']);
        $this->app->instance(BillingModeService::class, $billing);
        $sync = Mockery::mock(WalletSyncService::class);
        $sync->shouldReceive('syncClientBalanceById')->andReturn([]);
        $this->app->instance(WalletSyncService::class, $sync);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'home.example.com')) {
                return Http::response(str_contains($request->url(), 'payment-state') ? ['ok' => true] : $this->snapshot);
            }
            if (str_contains($request->url(), 'tour-capability')) {
                return Http::response($this->capability());
            }
            if (str_contains($request->url(), 'tour-locations')) {
                return Http::response(['cities' => [['id' => 5, 'name' => 'Destination city']]]);
            }
            if (str_contains($request->url(), 'tour-visitors/')) {
                $key = basename($request->url());
                if ($request->method() === 'PUT') {
                    $payload = $request->data();
                    $this->remote[$key] = ['revision' => $payload['revision'], 'state' => $payload['paused'] ? 'paused' : ($payload['window']['start'] > now()->timestamp ? 'scheduled' : 'live'), 'active' => true, 'home' => $payload['home'], 'window' => $payload['window'], 'environment' => $payload['environment'], 'url' => 'https://destination.example.com/escort/visitor'];
                }
                if ($request->method() === 'DELETE') {
                    $this->remote[$key] = ['revision' => $request['revision'], 'state' => 'removed'];
                }

                return Http::response($this->remote[$key] ?? ['state' => 'missing', 'revision' => 0]);
            }
            throw new \RuntimeException('Unexpected HTTP: '.$request->url());
        });
    }

    public function test_large_africa_catalogue_uses_cached_discovery_and_isolates_unavailable_market(): void
    {
        $ids = [$this->destination->id];
        for ($i = 1; $i < 40; $i++) {
            $destination = $this->destination->replicate();
            $destination->domain = "https://destination-$i.example.com";
            $destination->wp_api_url = $destination->domain.'/wp-json/exotic-crm-sync/v1';
            $destination->save();
            $ids[] = $destination->id;
        }
        $config = TourMarketSetting::first();
        $settings = $config->settings;
        $settings['passes'] = [['code' => 'africa_single', 'name' => 'Africa', 'region' => 'africa', 'currency' => 'KES', 'amount_minor' => 50000, 'days' => 7, 'market_limit' => 1, 'destination_platform_ids' => $ids, 'enabled' => true]];
        $config->update(['settings' => $settings]);
        $capabilities = Mockery::mock(\App\Services\Tours\CapabilityService::class);
        $capabilities->shouldReceive('requireDestination')->times(40)->andReturnUsing(function ($home, $destination) {
            if ($destination->id === $this->destination->id) {
                throw new \RuntimeException('Fixture unavailable receiver');
            }

            return [];
        });
        $this->app->instance(\App\Services\Tours\CapabilityService::class, $capabilities);
        $service = app(\App\Services\Tours\CatalogueService::class);
        $warmed = $service->refresh($this->home, $config);
        $this->assertCount(39, $warmed['verified']);
        $this->assertSame([$this->destination->id], $warmed['unavailable']);
        Http::assertSentCount(39);
        $catalogue = app(CheckoutService::class)->catalog($this->home, $this->input());
        $this->assertCount(39, $catalogue['destinations']);
        Http::assertSentCount(39); // Customer request performs no remote discovery.
        $settings['passes'][0]['amount_minor'] = 70000;
        $config->update(['settings' => $settings, 'revision' => 2]);
        $updated = app(CheckoutService::class)->catalog($this->home, $this->input());
        $this->assertSame(70000, $updated['destinations'][0]['passes'][0]['amount_minor']);
        Http::assertSentCount(39);
    }

    public function test_catalogue_evidence_expires_and_is_bound_to_endpoint_and_environment(): void
    {
        $service = app(\App\Services\Tours\CatalogueService::class);
        $service->refresh($this->home, TourMarketSetting::first());
        $this->assertNotNull($service->read($this->home, $this->destination, 'production'));
        $this->assertNull($service->read($this->home, $this->destination, 'sandbox'));
        $this->destination->wp_api_url = 'https://changed.example.com/wp-json/exotic-crm-sync/v1';
        $this->assertNull($service->read($this->home, $this->destination, 'production'));
        $this->destination->refresh();
        $this->travel(31)->minutes();
        $this->assertNull($service->read($this->home, $this->destination, 'production'));
    }

    public function test_tours_commands_do_nothing_before_schema_is_installed(): void
    {
        \Illuminate\Support\Facades\Schema::shouldReceive('hasTable')->with('tour_orders')->once()->andReturn(false);
        \Illuminate\Support\Facades\Schema::shouldReceive('hasTable')->with('tour_market_settings')->once()->andReturn(false);
        $this->artisan('tours:sweep')->expectsOutput('Tours schema is not installed; no work performed.')->assertExitCode(0);
        $this->artisan('tours:refresh-catalog')->expectsOutput('Tours schema is not installed; no work performed.')->assertExitCode(0);
        Queue::assertNothingPushed();
    }

    public function test_catalogue_discovery_does_not_retry_transport_failures(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new \Illuminate\Http\Client\ConnectionException('Fixture unreachable');
        });
        $result = app(\App\Services\Tours\CatalogueService::class)->refresh($this->home, TourMarketSetting::first());
        $this->assertSame(1, $calls);
        $this->assertSame([$this->destination->id], $result['unavailable']);
        $this->assertNull(app(\App\Services\Tours\CatalogueService::class)->read($this->home, $this->destination, 'production'));
    }

    public function test_catalogue_refresh_respects_an_expired_global_budget(): void
    {
        $result = app(\App\Services\Tours\CatalogueService::class)->refresh($this->home, TourMarketSetting::first(), microtime(true) - 1);
        $this->assertSame(['verified' => [], 'unavailable' => []], $result);
        Http::assertNothingSent();
    }

    private function capability(): array
    {
        return ['contract_version' => 1, 'available' => true, 'receiver_enabled' => true, 'theme_window_guard' => true, 'site_url' => 'https://destination.example.com', 'environment' => $this->snapshot['environment'], 'receiver_sources' => [['platform_id' => $this->home->id, 'origin' => 'https://home.example.com', 'media_origins' => ['https://home.example.com']]], 'features' => ['independent_pass_expiry', 'window_gating', 'home_canonical', 'noindex', 'separate_visiting_strip', 'idempotent_revision']];
    }

    private function input(): array
    {
        return ['wp_post_id' => 123, 'wp_user_id' => 77, 'trip_id' => 44, 'generation' => 'g1'];
    }

    private function quote(): array
    {
        return app(CheckoutService::class)->quote($this->home, $this->input());
    }

    private function intent(array $quote, string $method = 'wallet', string $request = 'first'): array
    {
        return app(CheckoutService::class)->intent($this->home, $this->input() + ['quote_token' => $quote['quote_token'], 'request_id' => $request, 'method' => $method, 'phone' => '+254700000000'], Request::create('/fixture', 'POST'));
    }

    public function test_signed_auth_uses_tours_mode_and_rejects_spoofed_or_stale_requests(): void
    {
        $wallet = Mockery::mock(\App\Services\WalletSettingsService::class);
        $wallet->shouldReceive('verifyWpToCrmBearer')->withArgs(fn ($platform, $environment, $token) => $platform->id === $this->home->id && $environment === 'production' && $token === 'fixture-bearer')->andReturn(true);
        $wallet->shouldReceive('wpToCrmHmacSecret')->withArgs(fn ($platform, $environment) => $environment === 'production')->andReturn('fixture-secret');
        $this->app->instance(\App\Services\WalletSettingsService::class, $wallet);
        $timestamp = (string) now()->timestamp;
        $key = 'config-read';
        $body = json_encode(['wp_post_id' => 123, 'wp_user_id' => 77]);
        $path = '/api/wp-svc/tours/config';
        $signature = hash_hmac('sha256', implode("\n", [$timestamp, 'POST', $path, (string) $this->home->id, $key, hash('sha256', $body)]), 'fixture-secret');
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer fixture-bearer', 'HTTP_X_EXOTIC_PLATFORM_ID' => (string) $this->home->id, 'HTTP_X_EXOTIC_TIMESTAMP' => $timestamp, 'HTTP_X_IDEMPOTENCY_KEY' => $key, 'HTTP_X_EXOTIC_SIGNATURE' => $signature, 'HTTP_ACCEPT' => 'application/json'];
        $this->call('POST', $path, [], [], [], $headers, $body)->assertOk()->assertJsonPath('environment', 'production');
        $this->call('POST', $path, [], [], [], $headers, json_encode(['wp_post_id' => 123, 'wp_user_id' => 99]))->assertUnauthorized();
        $headers['HTTP_X_EXOTIC_TIMESTAMP'] = (string) now()->subMinutes(6)->timestamp;
        $this->call('POST', $path, [], [], [], $headers, $body)->assertUnauthorized();
    }

    public function test_unconfigured_provider_and_stale_quote_do_not_create_attempts(): void
    {
        $quote = $this->quote();
        $billing = Mockery::mock(BillingModeService::class);
        $billing->shouldReceive('assertWalletAvailable')->andThrow(new \RuntimeException('disabled'));
        $billing->shouldReceive('providerContext')->andThrow(new \RuntimeException('no compatible binding'));
        $this->app->instance(BillingModeService::class, $billing);
        try {
            $this->intent($quote, 'kopokopo');
            $this->fail('unconfigured charged');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, TourPaymentAttempt::count());
    }

    public function test_sandbox_provider_payment_never_dispatches_or_credits_production(): void
    {
        $config = TourMarketSetting::first();
        $settings = $config->settings;
        $settings['environment'] = 'sandbox';
        $config->update(['settings' => $settings]);
        $this->snapshot['environment'] = 'sandbox';
        $gateway = Mockery::mock(BillingGatewayService::class);
        $gateway->shouldReceive('initiateTourPass')->andReturn(['action' => ['type' => 'pending']]);
        $this->app->instance(BillingGatewayService::class, $gateway);
        $quote = $this->quote();
        $this->intent($quote, 'kopokopo');
        $result = app(PaymentCompletionService::class)->complete(Payment::first(), ['amount' => 2000, 'currency' => 'KES']);
        $this->assertTrue($result['sandbox']);
        $this->assertSame('sandbox_paid', TourOrder::first()->state);
        $this->assertSame(0, WalletTransaction::count());
        Queue::assertNothingPushed();
    }

    public function test_provider_surfaces_polling_and_subscription_classification_are_isolated(): void
    {
        $registry = app(\App\Billing\Contracts\BillingProviderRegistry::class);
        $this->assertTrue($registry->find('kopokopo')->definition()->capabilities->supportsSurface(\App\Billing\Support\BillingSurface::TourPass));
        $this->assertTrue($registry->find('pawapay')->definition()->capabilities->supportsSurface(\App\Billing\Support\BillingSurface::TourPass));
        $payment = Payment::create(['platform_id' => $this->home->id, 'client_id' => $this->owner->id, 'amount' => 2000, 'currency' => 'KES', 'purpose' => Payment::PURPOSE_TOUR_PASS, 'status' => 'pending', 'provider_key' => 'kopokopo', 'provider_environment' => 'production']);
        $this->assertSame('tour_pass', app(\App\Services\ProviderStatusQueryOrchestrator::class)->resolveSurface($payment));
        $this->assertFalse($payment->isVisitorPayment());
        $this->assertFalse(Payment::excludingWalletTopups()->whereKey($payment->id)->exists());
        $this->assertSame(0, \App\Models\PaymentRecoveryCase::count());
        $this->assertSame(0, Deal::count());
    }

    public function test_quote_binds_saved_source_order_before_payment_for_durable_resume(): void
    {
        $quote = $this->quote();
        $order = TourOrder::first();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/tour-trips/44/payment-state') && $request['state'] === 'pending' && $request['order_reference'] === $quote['order_reference'] && $request['generation'] === 'g1' && $request['environment'] === 'production');
        $status = app(CheckoutService::class)->status($this->home, $order->public_id, $this->input());
        $this->assertSame('quoted', $status['state']);
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, TourPaymentAttempt::count());
        $this->assertSame(0, WalletTransaction::count());
        $again = $this->quote();
        $this->assertSame($quote['order_reference'], $again['order_reference']);
        $this->assertSame(1, TourOrder::count());
    }

    public function test_server_quotes_cheapest_pass_and_exact_allocations(): void
    {
        $quote = $this->quote();
        $this->assertSame(200000, $quote['total_minor']);
        $this->assertSame('gulf_week', $quote['passes'][0]['code']);
        $this->assertSame(200000, TourOrder::first()->stops->sum('allocation_minor'));
        $this->assertSame(0, Payment::count());
        $snapshot = $this->snapshot;
        $snapshot['stops'][0]['end'] = now()->addDays(11)->endOfDay()->timestamp;
        $price = app(PricingService::class)->price($this->home, $this->settings, $snapshot);
        $this->assertSame(400000, $price['amount_minor']);
    }

    public function test_owner_identity_and_source_generation_are_authoritative(): void
    {
        try {
            app(CheckoutService::class)->quote($this->home, array_replace($this->input(), ['wp_user_id' => 99]));
            $this->fail('owner accepted');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->snapshot['generation'] = 'changed';
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->quote();
    }

    public function test_intent_quote_cannot_be_attached_to_another_trip_version(): void
    {
        $quote = $this->quote();
        $bad = $this->input();
        $bad['trip_id'] = 99;
        try {
            app(CheckoutService::class)->intent($this->home, $bad + ['quote_token' => $quote['quote_token'], 'request_id' => 'wrong-trip', 'method' => 'wallet'], Request::create('/fixture', 'POST'));
            $this->fail('wrong trip accepted');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(0, TourPaymentAttempt::count());
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_capability_or_source_trust_failure_blocks_sale(): void
    {
        $this->snapshot['media'][0]['url'] = 'https://untrusted.example.com/media.jpg';
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->quote();
    }

    public function test_wallet_has_one_verified_debit_and_retry_is_idempotent(): void
    {
        app(WalletService::class)->credit($this->owner, 'KES', 5000, ['idempotency_key' => 'fixture-credit']);
        $quote = $this->quote();
        $result = $this->intent($quote);
        $this->assertSame('paid', $result['state']);
        $this->intent($quote);
        $this->assertSame(1, WalletTransaction::where('type', 'debit')->count());
        $this->assertSame(2000.0, (float) WalletTransaction::where('type', 'debit')->first()->amount);
        $this->assertSame(0, Deal::count());
        $this->assertSame(0, Payment::count());
        Queue::assertPushed(\App\Jobs\ReconcileTourOrder::class);
    }

    public function test_insufficient_wallet_never_confirms_order_or_debits(): void
    {
        $quote = $this->quote();
        try {
            $this->intent($quote);
            $this->fail('insufficient accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Top up', $e->getMessage());
        }$this->assertNull(TourOrder::first()->winning_attempt_id);
        $this->assertSame(0, WalletTransaction::where('type', 'debit')->count());
        $this->assertSame('failed', TourPaymentAttempt::first()->state);
    }

    public function test_sandbox_wallet_and_refund_cannot_mutate_real_ledger(): void
    {
        $config = TourMarketSetting::first();
        $settings = $config->settings;
        $settings['environment'] = 'sandbox';
        $config->update(['settings' => $settings]);
        $this->snapshot['environment'] = 'sandbox';
        $quote = $this->quote();
        $this->assertNotContains('wallet', $quote['methods']);
        try {
            $this->intent($quote);
            $this->fail('sandbox wallet accepted');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $order = TourOrder::first();
        try {
            app(DistributionService::class)->refund($order, $order->stops->first());
            $this->fail('sandbox refund');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }$this->assertSame(0, WalletTransaction::count());
    }

    public function test_external_uncertainty_stays_pending_and_blocks_new_charge(): void
    {
        $gateway = Mockery::mock(BillingGatewayService::class);
        $gateway->shouldReceive('initiateTourPass')->once()->andThrow(new \RuntimeException('timeout'));
        $this->app->instance(BillingGatewayService::class, $gateway);
        $quote = $this->quote();
        $result = $this->intent($quote, 'kopokopo');
        $this->assertSame('pending', $result['attempt']['state']);
        $this->intent($quote, 'kopokopo');
        $this->assertSame(1, Payment::count());
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->intent($quote, 'pawapay', 'other');
    }

    public function test_callback_before_provider_response_keeps_winning_attempt_paid(): void
    {
        $gateway = Mockery::mock(BillingGatewayService::class);
        $gateway->shouldReceive('initiateTourPass')->andReturnUsing(function ($payment) {
            app(PaymentCompletionService::class)->complete($payment, ['amount' => 2000, 'currency' => 'KES']);

            return ['action' => ['type' => 'pending']];
        });
        $this->app->instance(BillingGatewayService::class, $gateway);
        $result = $this->intent($this->quote(), 'kopokopo');
        $this->assertSame('paid', $result['state']);
        $this->assertSame('paid', $result['attempt']['state']);
    }

    public function test_success_callback_during_status_poll_cannot_be_downgraded_by_failed_poll(): void
    {
        $gateway = Mockery::mock(BillingGatewayService::class);
        $gateway->shouldReceive('initiateTourPass')->andReturn(['action' => ['type' => 'pending']]);
        $this->app->instance(BillingGatewayService::class, $gateway);
        $quote = $this->quote();
        $this->intent($quote, 'kopokopo');
        $order = TourOrder::first();
        $poll = Mockery::mock(\App\Services\ProviderStatusQueryOrchestrator::class);
        $poll->shouldReceive('verify')->once()->andReturnUsing(function ($payment) {
            app(PaymentCompletionService::class)->complete($payment, ['amount' => 2000, 'currency' => 'KES']);

            return ['status' => 'failed'];
        });
        $poll->shouldReceive('decideMutation')->andReturn(['decision' => 'apply_failed']);
        $this->app->instance(\App\Services\ProviderStatusQueryOrchestrator::class, $poll);
        $result = app(CheckoutService::class)->status($this->home, $order->public_id, $this->input());
        $this->assertSame('paid', $result['state']);
        $this->assertSame('paid', $result['attempt']['state']);
        $this->assertContains(Payment::first()->status, Payment::SUCCESSFUL_STATUSES);
    }

    public function test_missing_or_wrong_settlement_stays_review_and_never_activates(): void
    {
        $gateway = Mockery::mock(BillingGatewayService::class);
        $gateway->shouldReceive('initiateTourPass')->andReturn(['action' => ['type' => 'pending']]);
        $this->app->instance(BillingGatewayService::class, $gateway);
        $quote = $this->quote();
        $this->intent($quote, 'kopokopo');
        $payment = Payment::first();
        $result = app(PaymentCompletionService::class)->complete($payment, []);
        $this->assertTrue($result['review']);
        $this->assertNull(TourOrder::first()->winning_attempt_id);
        $this->assertSame(0, WalletTransaction::count());
        Queue::assertNothingPushed();
    }

    public function test_duplicate_success_and_late_other_attempt_do_not_grant_twice(): void
    {
        $gateway = Mockery::mock(BillingGatewayService::class);
        $gateway->shouldReceive('initiateTourPass')->andReturn(['action' => ['type' => 'pending']]);
        $this->app->instance(BillingGatewayService::class, $gateway);
        $quote = $this->quote();
        $this->intent($quote, 'kopokopo');
        $attempt = TourPaymentAttempt::first();
        $attempt->update(['state' => 'failed']);
        $attempt->payment->update(['status' => 'failed']);
        $this->intent($quote, 'pawapay', 'second');
        $second = TourPaymentAttempt::latest('id')->first();
        $result = app(PaymentCompletionService::class)->complete($second->payment, ['amount' => 2000, 'currency' => 'KES']);
        $this->assertTrue($result['paid']);
        app(PaymentCompletionService::class)->complete($second->payment, ['amount' => 2000, 'currency' => 'KES']);
        $late = app(PaymentCompletionService::class)->complete($attempt->payment, ['amount' => 2000, 'currency' => 'KES']);
        $this->assertTrue($late['review']);
        $this->assertSame($second->id, TourOrder::first()->winning_attempt_id);
        $this->assertSame('duplicate_settlement', $attempt->fresh()->state);
        $this->assertSame(0, Deal::count());
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_distribution_keeps_paid_access_after_home_subscription_expiry_and_cancel_removes(): void
    {
        app(WalletService::class)->credit($this->owner, 'KES', 5000, ['idempotency_key' => 'fixture-credit']);
        $quote = $this->quote();
        $this->intent($quote);
        $order = TourOrder::first();
        $this->snapshot['home_subscription_end'] = now()->subDay()->timestamp;
        app(DistributionService::class)->reconcile($order->id);
        $stop = $order->stops()->first();
        $this->assertSame('scheduled', $stop->state);
        $this->assertNotNull($stop->remote_url);
        $revision = $stop->revision;
        app(DistributionService::class)->reconcile($order->id);
        $this->assertSame($revision, $stop->fresh()->revision);
        app(CheckoutService::class)->cancel($this->home, $order->public_id, $this->input());
        app(DistributionService::class)->reconcile($order->id);
        $this->assertSame('removed', $stop->fresh()->state);
        $this->assertSame(1, WalletTransaction::where('type', 'credit')->count());
    }

    public function test_cancellation_revokes_destinations_even_when_source_media_or_profile_is_missing(): void
    {
        app(WalletService::class)->credit($this->owner, 'KES', 5000, ['idempotency_key' => 'fixture-credit']);
        $quote = $this->quote();
        $this->intent($quote);
        $order = TourOrder::first();
        app(DistributionService::class)->reconcile($order->id);
        $order->update(['state' => 'cancelled', 'cancelled_at' => now()]);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['https://home.example.com/*' => Http::response(['message' => 'source media deleted'], 409), '*' => function ($request) {
            $key = basename($request->url());
            if (str_contains($request->url(), 'tour-capability')) {
                return Http::response($this->capability());
            }if ($request->method() === 'DELETE') {
                $this->remote[$key] = ['state' => 'removed', 'revision' => $request['revision']];
            }

            return Http::response($this->remote[$key] ?? []);
        }]);
        try {
            app(DistributionService::class)->reconcile($order->id);
        } catch (\Illuminate\Http\Client\RequestException) {
        }
        $this->assertSame('removed', $order->stops()->first()->state);
        $this->assertSame(0, WalletTransaction::where('reference_type', 'tour_failure_refund')->count());
    }

    public function test_invalid_source_snapshot_revokes_an_existing_paid_copy_without_failure_refund(): void
    {
        app(WalletService::class)->credit($this->owner, 'KES', 5000, ['idempotency_key' => 'fixture-credit']);
        $quote = $this->quote();
        $this->intent($quote);
        $order = TourOrder::first();
        app(DistributionService::class)->reconcile($order->id);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['https://home.example.com/*' => Http::response(['message' => 'profile deleted'], 404), '*' => function ($request) {
            $key = basename($request->url());
            if (str_contains($request->url(), 'tour-capability')) {
                return Http::response($this->capability());
            }if ($request->method() === 'DELETE') {
                $this->remote[$key] = ['state' => 'removed', 'revision' => $request['revision']];
            }

            return Http::response($this->remote[$key] ?? []);
        }]);
        app(DistributionService::class)->reconcile($order->id);
        $this->assertSame('removed', $order->stops()->first()->state);
        $this->assertSame('paid', $order->fresh()->state);
        $this->assertSame(0, WalletTransaction::where('reference_type', 'tour_failure_refund')->count());
    }

    public function test_missed_scheduler_window_refunds_never_live_destination_after_removal(): void
    {
        app(WalletService::class)->credit($this->owner, 'KES', 5000, ['idempotency_key' => 'fixture-credit']);
        $quote = $this->quote();
        $this->intent($quote);
        $order = TourOrder::first();
        $this->travelTo($order->stops->first()->ends_at->copy()->addHour());
        app(DistributionService::class)->reconcile($order->id);
        $stop = $order->stops()->first();
        $this->assertSame('refunded', $stop->state);
        $this->assertSame(1, WalletTransaction::where('reference_type', 'tour_failure_refund')->count());
        app(DistributionService::class)->reconcile($order->id);
        $this->assertSame(1, WalletTransaction::where('reference_type', 'tour_failure_refund')->count());
        $this->travelBack();
    }

    public function test_failure_refund_is_once_after_verified_destination_removal(): void
    {
        app(WalletService::class)->credit($this->owner, 'KES', 5000, ['idempotency_key' => 'fixture-credit']);
        $quote = $this->quote();
        $this->intent($quote);
        $order = TourOrder::first();
        $stop = $order->stops()->first();
        app(DistributionService::class)->refund($order, $stop);
        app(DistributionService::class)->refund($order, $stop);
        $this->assertSame(1, WalletTransaction::where('reference_type', 'tour_failure_refund')->count());
        $this->assertSame('refunded', $stop->fresh()->state);
        $this->assertSame('removed', $this->remote[$stop->tour_key]['state']);
        Queue::assertPushed(\App\Jobs\SendTourFailureNotice::class);
    }
}
