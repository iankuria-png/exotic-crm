<?php

namespace Tests\Feature\FlexibleAmounts;

use App\Models\BillingSubscriptionRule;
use App\Models\FlexibleCheckoutAttempt;
use App\Models\Payment;
use App\Models\ProductPrice;
use App\Services\FlexibleAmounts\FlexibleAttemptGuard;
use App\Services\FlexibleAmounts\FlexibleFulfilmentService;
use App\Services\FlexibleAmounts\FlexibleRulesService;
use App\Services\FlexibleAmounts\FlexibleSelfCheckoutService;
use App\Services\HostedCheckoutService;
use App\Services\WalletSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class FlexibleAmountsTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\FlexibleAmountsFixture;

    public function test_quote_extends_live_current_tier_from_expiry_and_excludes_custom_prices(): void
    {
        $q = $this->quote();
        $this->assertSame(4, $q['days']);
        $this->assertSame('880.00', $q['charge']);
        $this->assertSame($this->client->escort_expire, \Carbon\Carbon::parse($q['starts_at'])->timestamp);
        $this->assertSame(30, ProductPrice::find($q['base_product_price_id'])->duration_days);
        $this->assertSame('flexible', $q['composition'][0]['kind']);
        $this->assertSame(1, count(app(FlexibleRulesService::class)->checks($this->market, app(FlexibleRulesService::class)->rules($this->market))['plans'][0]['custom_prices']));
    }

    public function test_off_market_is_unchanged_and_no_quote_is_available(): void
    {
        BillingSubscriptionRule::where('market_id', $this->market->id)->update(['flexible_amounts' => null]);
        $this->assertSame('off', app(FlexibleRulesService::class)->rules($this->market)['mode']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->quote();
    }

    public function test_live_profile_cannot_switch_tier(): void
    {
        $this->state['current_tier'] = 'premium';
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->quote();
    }

    public function test_undercutting_settings_are_blocked_without_mutation(): void
    {
        $monthly = ProductPrice::where('product_id', $this->product->id)->where('duration_days', 30)->first();
        $monthly->update(['price' => 4000]);
        $rules = app(FlexibleRulesService::class)->rules($this->market);
        try {
            app(FlexibleRulesService::class)->save($this->market, $rules, 1);
            $this->fail('Save should be blocked.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('Minimum passing uplift', json_encode($e->errors()));
        }
        $this->assertSame(1, app(FlexibleRulesService::class)->rules($this->market)['rules_version']);
    }

    public function test_rules_save_is_versioned_audited_and_preserves_other_settings(): void
    {
        $actor = \App\Models\User::factory()->create();
        BillingSubscriptionRule::where('market_id', $this->market->id)->update(['free_trial_json' => ['enabled' => true, 'duration_days' => 7]]);
        $rules = app(FlexibleRulesService::class)->rules($this->market);
        $rules['mode'] = 'flex';
        $saved = app(FlexibleRulesService::class)->save($this->market, $rules, $actor->id);
        $this->assertSame(2, $saved['rules_version']);
        $this->assertSame(7, BillingSubscriptionRule::where('market_id', $this->market->id)->first()->free_trial_json['duration_days']);
        $this->assertDatabaseHas('audit_log', ['platform_id' => $this->market->id, 'entity_type' => 'flexible_amounts', 'actor_id' => $actor->id]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(FlexibleRulesService::class)->save($this->market, $rules, $actor->id);
    }

    public function test_checkout_recomputes_catalogue_and_returns_409_before_reserving(): void
    {
        $r = $this->request();
        ProductPrice::where('product_id', $this->product->id)->where('duration_days', 7)->update(['price' => 1490]);
        try {
            app(FlexibleSelfCheckoutService::class)->start($r);
            $this->fail('Expected price update.');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->status());
            $this->assertSame('price_updated', $e->getResponse()->getData(true)['code']);
        }
        $this->assertDatabaseCount('flexible_checkout_attempts', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_flexible_public_route_requires_site_authentication(): void
    {
        $this->postJson('/api/self-checkout', ['pricing_mode' => 'flexible'])->assertUnauthorized();
        $this->postJson('/api/wp-svc/flexible-amounts/quote', ['wp_user_id' => $this->client->wp_user_id])->assertUnauthorized();
    }

    public function test_signed_quote_requires_bearer_hmac_customer_assertion_and_rate_limit(): void
    {
        $service = $this->mock(WalletSettingsService::class);
        $service->shouldReceive('verifyWpToCrmBearer')->andReturnUsing(fn ($p, $e, $b) => $b === 'fixture-bearer');
        $service->shouldReceive('wpToCrmHmacSecret')->andReturn('fixture-hmac');
        $path = '/api/wp-svc/flexible-amounts/quote';
        $input = ['wp_user_id' => $this->client->wp_user_id, 'authenticated_customer' => true, 'product_id' => $this->product->id, 'amount' => '1000'];
        $send = function (array $data, bool $signed = true) use ($path) {
            $body = json_encode($data);
            $key = (string) Str::uuid();
            $ts = (string) now()->timestamp;
            $sig = hash_hmac('sha256', implode("\n", [$ts, 'POST', $path, (string) $this->market->id, $key, hash('sha256', $body)]), 'fixture-hmac');

            return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer fixture-bearer', 'HTTP_X_EXOTIC_PLATFORM_ID' => $this->market->id, 'HTTP_X_EXOTIC_TIMESTAMP' => $ts, 'HTTP_X_IDEMPOTENCY_KEY' => $key, 'HTTP_X_EXOTIC_SIGNATURE' => $signed ? $sig : 'wrong'], $body);
        };
        $send($input, false)->assertUnauthorized();
        $send(array_replace($input, ['authenticated_customer' => false]))->assertForbidden();
        $send($input)->assertOk()->assertJsonPath('days', 4);
        RateLimiter::hit('flexible-quote:'.$this->client->id, 60);
        for ($i = 0; $i < 90; $i++) {
            RateLimiter::hit('flexible-quote:'.$this->client->id, 60);
        }
        $send($input)->assertStatus(429);
    }

    public function test_checkout_snapshot_and_durable_replay_survive_lost_response_with_wallet_off(): void
    {
        $calls = 0;
        $this->mock(HostedCheckoutService::class)->shouldReceive('initializePawaPay')->andReturnUsing(function ($p) use (&$calls) {
            $calls++;
            $this->assertDatabaseHas('flexible_checkout_attempts', ['payment_id' => $p->id, 'state' => 'dispatching']);
            throw new \RuntimeException('Simulated lost response');
        });
        $request = $this->request();
        $one = app(FlexibleSelfCheckoutService::class)->start($request);
        $two = app(FlexibleSelfCheckoutService::class)->start($request);
        $this->assertSame(1, $calls);
        $this->assertSame($one['payment_id'], $two['payment_id']);
        $this->assertDatabaseCount('payments', 1);
        $payment = Payment::findOrFail($one['payment_id']);
        $this->assertSame('880.00', $payment->amount);
        $this->assertSame(4, data_get($payment->payment_data, 'duration_days'));
        $this->assertSame('flexible', data_get($payment->payment_data, 'pricing_mode'));
        $this->assertSame('uncertain', FlexibleCheckoutAttempt::first()->state);
        try {
            app(FlexibleAttemptGuard::class)->assertClient($this->client->id);
            $this->fail('Must block another writer.');
        } catch (\App\Exceptions\ClientLifecycleMutationException) {
            $this->assertTrue(true);
        }
        $payment->update(['status' => 'completed']);
        $this->expectException(\App\Exceptions\ClientLifecycleMutationException::class);
        app(FlexibleAttemptGuard::class)->assertClient($this->client->id);
    }

    public function test_exact_days_and_effective_incentive_are_in_confirmation_hash(): void
    {
        $q = $this->quote(['requested_days' => 6]);
        $this->assertSame(6, $q['days']);
        BillingSubscriptionRule::where('market_id', $this->market->id)->update(['discount_json' => ['self_service_incentive' => ['enabled' => true, 'percent' => 10, 'sources' => ['self_checkout']]]]);
        $discounted = $this->quote(['requested_days' => 6]);
        $this->assertSame('1188.00', $discounted['charge']);
        $this->assertNotSame($q['confirmation_hash'], $discounted['confirmation_hash']);
    }

    public function test_relative_day_fulfilment_recovers_saved_operation_before_current_tier_check(): void
    {
        $q = $this->quote();
        $key = (string) Str::uuid();
        $payment = Payment::factory()->create(['platform_id' => $this->market->id, 'client_id' => $this->client->id, 'product_id' => $this->product->id, 'payment_data' => ['pricing_mode' => 'flexible', 'flexible_operation_key' => $key, 'flexible_quote' => $q]]);
        $deal = \App\Models\Deal::factory()->create(['platform_id' => $this->market->id, 'client_id' => $this->client->id, 'plan_type' => 'vip', 'product_id' => $this->product->id, 'duration_days' => 4]);
        $result = ['state' => 'applied', 'operation_key' => $key, 'post_id' => $this->client->wp_post_id, 'crm_payment_id' => $payment->id, 'crm_deal_id' => $deal->id, 'product_type' => 'vip', 'duration_days' => 4, 'escort_expire' => now()->addDays(10)->timestamp];
        $this->wp->shouldReceive('payLinkOperation')->with($key)->once()->andReturn(['operation' => $result]);
        $this->wp->shouldNotReceive('activatePayLink');
        $this->state['current_tier'] = 'premium';
        $this->assertSame($result, app(FlexibleFulfilmentService::class)->apply($payment, $deal, $this->client, 4));
    }
}
