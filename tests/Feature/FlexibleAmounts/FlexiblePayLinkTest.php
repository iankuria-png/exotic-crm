<?php

namespace Tests\Feature\FlexibleAmounts;

use App\Models\BillingMarketProviderBinding;
use App\Models\BillingProviderProfile;
use App\Models\BillingSubscriptionRule;
use App\Models\PayLinkCharge;
use App\Models\PayLinkSetting;
use App\Services\FlexibleAmounts\FlexibleLinkOfferService;
use App\Services\FlexibleAmounts\FlexibleQuoteService;
use App\Services\PayLink\CheckoutService;
use App\Services\PayLink\LinkService;
use App\Services\PayLink\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FlexiblePayLinkTest extends TestCase
{
    use RefreshDatabase, \Tests\Support\FlexibleAmountsFixture;

    private string $providerState = 'COMPLETED';

    private int $deposits = 0;

    private function setupLink(string $environment = 'sandbox'): array
    {
        $profile = BillingProviderProfile::where('market_id', $this->market->id)->first();
        $profile->update(['environment' => $environment]);
        BillingMarketProviderBinding::create(['market_id' => $this->market->id, 'provider_profile_id' => $profile->id, 'billing_surface' => 'pay_link', 'enabled' => true, 'self_service_enabled' => true, 'execution_mode' => 'direct', 'priority' => 1]);
        $price = $this->product->activePrices()->where('duration_days', 7)->first();
        PayLinkSetting::create(['platform_id' => $this->market->id, 'rollout_mode' => $environment === 'sandbox' ? 'test' : 'live', 'test_client_ids' => [$this->client->id], 'test_device_hashes' => [hash('sha256', str_repeat('a', 64))], 'offers_json' => [['product_price_id' => $price->id]], 'config_revision' => 1]);
        $link = app(LinkService::class)->mint($this->client);
        Http::fake(function ($r) {
            if (str_ends_with($r->url(), '/v2/active-conf')) {
                return Http::response(['signatureConfiguration' => ['signedRequestsOnly' => false], 'countries' => [['country' => 'KEN', 'providers' => [['provider' => 'MPESA_KEN', 'currencies' => [['currency' => 'KES', 'operationTypes' => [['DEPOSIT' => ['authType' => 'PROVIDER_AUTH', 'pinPrompt' => 'AUTOMATIC', 'decimalsInAmount' => 'NONE', 'status' => 'OPERATIONAL', 'minTransactionLimit' => '1', 'maxTransactionLimit' => '150000']]]]]]]]]]);
            }
            if (str_ends_with($r->url(), '/v2/predict-provider')) {
                return Http::response(['phoneNumber' => $r['phoneNumber'], 'country' => 'KEN', 'provider' => 'MPESA_KEN']);
            }
            if ($r->method() === 'POST' && str_ends_with($r->url(), '/v2/deposits')) {
                $this->deposits++;

                return Http::response(['status' => 'ACCEPTED', 'depositId' => $r['depositId']]);
            }
            $c = PayLinkCharge::where('deposit_id', basename($r->url()))->firstOrFail();

            return Http::response(['status' => 'FOUND', 'data' => ['depositId' => $c->deposit_id, 'status' => $this->providerState, 'amount' => $c->amount, 'currency' => $c->currency,
                'payer' => ['type' => 'MMO', 'accountDetails' => ['phoneNumber' => $c->phone, 'provider' => 'MPESA_KEN']], 'clientReferenceId' => $c->public_ref, 'providerTransactionId' => 'LOCAL-FIXTURE',
                'failureReason' => ['failureCode' => 'INSUFFICIENT_FUNDS', 'failureMessage' => 'Insufficient funds']]]);
        });

        return ['code' => $link->code_encrypted, 'session_proof' => str_repeat('a', 64), 'visitor_ip' => '192.0.2.14', 'config_revision' => 1, 'phone' => null, 'confirm_saved_phone' => true, 'provider_key' => 'pawapay', 'link' => $link];
    }

    private function flexibleInput(array $input, string $amount = '1000'): array
    {
        $q = app(FlexibleQuoteService::class)->quote($this->market, $this->client, ['product_id' => $this->product->id, 'amount' => $amount], 'pay_link');
        unset($input['link']);

        return $input + ['pricing_mode' => 'flexible', 'product_id' => $this->product->id, 'amount' => $amount, 'rules_version' => $q['rules_version'], 'catalogue_hash' => $q['catalogue_hash'], 'confirmation_hash' => $q['confirmation_hash']];
    }

    public function test_sandbox_flexible_offer_snapshot_days_actual_charge_and_idempotency(): void
    {
        $input = $this->flexibleInput($this->setupLink());
        $key = (string) Str::uuid();
        $one = app(CheckoutService::class)->start($this->market, $input, $key);
        $two = app(CheckoutService::class)->start($this->market, $input, $key);
        $this->assertSame($one['charge_ref'], $two['charge_ref']);
        $this->assertSame(1, $this->deposits);
        $c = PayLinkCharge::first();
        $this->assertSame(4, $c->duration_days);
        $this->assertSame('880.00', $c->amount);
        $this->assertSame('880.00', $c->payment->deal->amount);
        $this->assertSame('flexible', data_get($c->payment->payment_data, 'pricing_mode'));
        app(ReconciliationService::class)->reconcile($c->id);
        $this->assertSame('suppressed_sandbox', $c->fresh()->activation_state);
        $this->assertDatabaseCount('pay_link_receipts', 0);
        $this->assertDatabaseCount('commissions', 0);
    }

    public function test_insufficient_funds_returns_only_lower_same_plan_rescue_and_never_on_pending(): void
    {
        $this->providerState = 'FAILED';
        $input = $this->flexibleInput($this->setupLink(), '3000');
        app(CheckoutService::class)->start($this->market, $input, (string) Str::uuid());
        $c = PayLinkCharge::first();
        $this->assertSame([], app(CheckoutService::class)->view($c)['rescue']);
        app(ReconciliationService::class)->reconcile($c->id);
        $view = app(CheckoutService::class)->view($c->fresh());
        $this->assertSame('insufficient_funds', $view['failure_category']);
        $this->assertSame([3, 7], array_column($view['rescue'], 'days'));
        foreach ($view['rescue'] as $q) {
            $this->assertLessThan(3000, (float) $q['charge']);
            $this->assertSame($this->product->id, $q['product_id']);
        }
    }

    public function test_crm_offer_context_is_signed_bound_and_independently_gated(): void
    {
        $input = $this->setupLink();
        $token = app(FlexibleLinkOfferService::class)->issue($input['link'], '1300');
        $this->assertSame('1300', app(FlexibleLinkOfferService::class)->resolve($input['link'], $token)['suggested_amount']);
        $rules = BillingSubscriptionRule::where('market_id', $this->market->id)->first();
        $saved = $rules->flexible_amounts;
        $saved['surfaces']['pay_link'] = false;
        $rules->update(['flexible_amounts' => $saved]);
        $resolved = app(CheckoutService::class)->resolve($this->market, $input + ['offer_context' => $token]);
        $this->assertSame('both', $resolved['flexible_amounts']['mode']);
        $plain = app(CheckoutService::class)->resolve($this->market, $input);
        $this->assertSame('off', $plain['flexible_amounts']['mode']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(FlexibleLinkOfferService::class)->resolve($input['link'], $token.'x');
    }

    public function test_staff_preview_shows_server_priced_days_and_respects_gates(): void
    {
        $admin = \App\Models\User::query()->create(['name' => 'Admin', 'email' => 'admin-'.Str::random(6).'@example.test', 'password' => bcrypt('password'), 'role' => 'admin', 'assigned_market_ids' => [], 'status' => 'active']);
        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $preview = $this->postJson("/api/crm/clients/{$this->client->id}/pay-link/quote", ['amount' => 1000])->assertOk()->json();
        $this->assertTrue($preview['available']);
        $this->assertSame('vip', $preview['current_tier']);
        $vip = collect($preview['results'])->firstWhere('tier', 'vip');
        $this->assertTrue($vip['valid']);
        $this->assertSame(4, $vip['days']);
        $this->assertSame('880.00', $vip['charge']);
        $this->assertSame([], $this->postJson("/api/crm/clients/{$this->client->id}/pay-link/quote", [])->assertOk()->json('results'));

        $rules = BillingSubscriptionRule::where('market_id', $this->market->id)->first();
        $saved = $rules->flexible_amounts;
        $saved['surfaces']['crm_link'] = false;
        $rules->update(['flexible_amounts' => $saved]);
        $this->assertFalse($this->postJson("/api/crm/clients/{$this->client->id}/pay-link/quote", ['amount' => 1000])->assertOk()->json('available'));

        $field = \App\Models\User::query()->create(['name' => 'Field', 'email' => 'field-'.Str::random(6).'@example.test', 'password' => bcrypt('password'), 'role' => 'field_sales', 'assigned_market_ids' => [$this->market->id], 'status' => 'active']);
        \Laravel\Sanctum\Sanctum::actingAs($field);
        $this->postJson("/api/crm/clients/{$this->client->id}/pay-link/quote", ['amount' => 1000])->assertForbidden();
    }

    public function test_changed_flexible_input_cannot_reuse_a_paid_attempt_key(): void
    {
        $input = $this->flexibleInput($this->setupLink());
        $key = (string) Str::uuid();
        app(CheckoutService::class)->start($this->market, $input, $key);
        $input['amount'] = '1300';
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(CheckoutService::class)->start($this->market, $input, $key);
    }

    public function test_flexible_activation_emits_receipt_timeline_and_commissions_once_on_actual_amount(): void
    {
        $agent = \App\Models\User::factory()->create(['role' => 'field_sales']);
        $this->client->update(['assigned_to' => $agent->id]);
        $input = $this->flexibleInput($this->setupLink('production'));
        $this->wp->shouldReceive('payLinkOperation')->andReturn(['operation' => null]);
        $this->wp->shouldReceive('activatePayLink')->once()->andReturnUsing(fn ($post, $body) => ['state' => 'applied', 'operation_key' => $body['operation_key'], 'post_id' => $post, 'crm_payment_id' => $body['crm_payment_id'],
            'crm_deal_id' => $body['crm_deal_id'], 'duration_days' => $body['duration_days'], 'product_type' => $body['product_type'], 'escort_expire' => now()->addDays(10)->timestamp, 'profile_url' => 'https://flexible.test/escort/local/']);
        app(CheckoutService::class)->start($this->market, $input, (string) Str::uuid());
        $c = PayLinkCharge::first();
        app(ReconciliationService::class)->reconcile($c->id);
        app(ReconciliationService::class)->reconcile($c->id);
        $this->assertSame('applied', $c->fresh()->activation_state);
        $this->assertDatabaseCount('pay_link_receipts', 1);
        $this->assertSame(3, \App\Models\TimelineEvent::where('content->pricing_mode', 'flexible')->count());
        $this->assertStringContainsString('4 days', \App\Models\PayLinkReceipt::first()->body);
        $this->assertDatabaseHas('commissions', ['deal_id' => $c->deal_id, 'basis_amount' => '880.00']);
        $this->assertSame('self_checkout', app(\App\Services\Rebates\RebateChannelResolver::class)->resolve($c->payment->fresh()));
        $this->market->update(['wallet_settings' => ['enabled' => true]]);
        $this->client->update(['verified' => true, 'wallet_currency' => 'KES']);
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $program = app(\App\Services\Rebates\RebateProgramService::class)->forPlatform($this->market->fresh());
        $draft = $program->draft_json;
        $draft['rollout_mode'] = 'live';
        $program->update(['draft_json' => $draft]);
        app(\App\Services\Rebates\RebateProgramService::class)->publish($this->market->fresh(), 1, 'Isolated flexible test', $admin->id);
        $this->mock(\App\Services\WalletSyncService::class, fn ($m) => $m->shouldReceive('syncClientBalanceById', 'syncClientBalance')->andReturn(['status' => 'skipped']));
        $grant = app(\App\Services\Rebates\RebateGrantService::class)->grantFor($c->payment->fresh());
        $this->assertNotNull($grant);
        $this->assertSame('880.00', (string) $grant->base_amount);
        $this->assertSame($grant->id, app(\App\Services\Rebates\RebateGrantService::class)->grantFor($c->payment->fresh())->id);

    }
}
