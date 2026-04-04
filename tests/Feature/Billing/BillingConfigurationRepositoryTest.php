<?php

namespace Tests\Feature\Billing;

use App\Billing\Repositories\BillingConfigurationRepository;
use App\Models\BillingMarketProviderBinding;
use App\Models\BillingProviderProfile;
use App\Models\BillingRoutingDecision;
use App\Models\BillingRoutingRule;
use App\Models\BillingSubscriptionRule;
use App\Models\BillingWalletRule;
use App\Models\Payment;
use App\Models\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BillingConfigurationRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_configuration_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('billing_provider_profiles'));
        $this->assertTrue(Schema::hasTable('billing_market_provider_bindings'));
        $this->assertTrue(Schema::hasTable('billing_routing_rules'));
        $this->assertTrue(Schema::hasTable('billing_wallet_rules'));
        $this->assertTrue(Schema::hasTable('billing_subscription_rules'));
        $this->assertTrue(Schema::hasTable('billing_routing_decisions'));
        $this->assertTrue(Schema::hasColumn('billing_routing_decisions', 'snapshot_json'));
    }

    public function test_repository_reads_market_scoped_billing_configuration_models(): void
    {
        $market = Platform::factory()->create();
        $otherMarket = Platform::factory()->create();
        $payment = Payment::factory()->create([
            'platform_id' => $market->id,
        ]);

        $globalProfile = BillingProviderProfile::query()->create([
            'provider_type_key' => 'nowpayments',
            'profile_name' => 'Global crypto',
            'country_code' => null,
            'market_id' => null,
            'merchant_scope_json' => ['scope' => 'global'],
            'environment' => 'production',
            'config_json' => ['mode' => 'invoice'],
            'secrets_json' => ['api_key' => 'encrypted'],
            'active' => true,
        ]);

        $marketProfile = BillingProviderProfile::query()->create([
            'provider_type_key' => 'daraja',
            'profile_name' => 'Kenya live shortcode',
            'country_code' => 'KE',
            'market_id' => $market->id,
            'merchant_scope_json' => ['scope' => 'market'],
            'environment' => 'production',
            'config_json' => ['short_code' => '123456'],
            'secrets_json' => ['consumer_key' => 'encrypted'],
            'active' => true,
        ]);

        $binding = BillingMarketProviderBinding::query()->create([
            'market_id' => $market->id,
            'provider_profile_id' => $marketProfile->id,
            'billing_surface' => 'wallet_funding',
            'enabled' => true,
            'operator_enabled' => true,
            'self_service_enabled' => false,
            'execution_mode' => 'direct',
            'priority' => 10,
            'fallback_group' => 'kenya-wallet',
            'restriction_json' => ['currency_codes' => ['KES']],
            'notes' => 'Primary Kenya wallet route',
        ]);

        BillingRoutingRule::query()->create([
            'market_id' => $market->id,
            'billing_surface' => 'wallet_funding',
            'primary_binding_id' => $binding->id,
            'fallback_strategy_json' => ['providers' => ['kopokopo']],
            'risk_policy_json' => ['mode' => 'direct_preferred'],
            'active' => true,
        ]);

        BillingWalletRule::query()->create([
            'market_id' => $market->id,
            'enabled' => true,
            'currency_code' => 'KES',
            'topup_preset_json' => ['100.00', '500.00'],
            'limit_json' => ['max_single_topup' => '5000.00'],
            'auto_renew_json' => ['enabled' => true],
            'ui_json' => ['show_wallet_first' => true],
        ]);

        BillingSubscriptionRule::query()->create([
            'market_id' => $market->id,
            'activation_method_json' => ['wallet', 'payment_link'],
            'renewal_method_json' => ['wallet_auto_renew', 'payment_link'],
            'free_trial_json' => ['enabled' => true],
            'discount_json' => ['pin_required' => true],
            'expiry_policy_json' => ['grace_days' => 1],
        ]);

        $decision = BillingRoutingDecision::query()->create([
            'payment_id' => $payment->id,
            'market_id' => $market->id,
            'billing_surface' => 'wallet_funding',
            'chosen_binding_id' => $binding->id,
            'provider_profile_id' => $marketProfile->id,
            'provider_type_key' => 'daraja',
            'execution_mode' => 'direct',
            'environment' => 'production',
            'fallback_taken' => false,
            'decision_version' => 1,
            'shadow_diff_json' => null,
            'surface_cutover_flag' => null,
            'snapshot_json' => ['provider_type_key' => 'daraja', 'profile_name' => 'Kenya live shortcode'],
            'immutable_until_terminal_state' => true,
            'decision_json' => ['resolved_from' => 'rule'],
        ]);

        BillingProviderProfile::query()->create([
            'provider_type_key' => 'kopokopo',
            'profile_name' => 'Other market profile',
            'country_code' => 'KE',
            'market_id' => $otherMarket->id,
            'merchant_scope_json' => ['scope' => 'market'],
            'environment' => 'production',
            'config_json' => [],
            'secrets_json' => [],
            'active' => true,
        ]);

        $repository = app(BillingConfigurationRepository::class);

        $profiles = $repository->providerProfilesForMarket((int) $market->id, 'production');
        $bindings = $repository->activeBindingsForMarket((int) $market->id, 'wallet_funding');
        $route = $repository->routingRuleForMarket((int) $market->id, 'wallet_funding');
        $walletRule = $repository->walletRuleForMarket((int) $market->id);
        $subscriptionRule = $repository->subscriptionRuleForMarket((int) $market->id);
        $latestDecision = $repository->latestRoutingDecisionForPayment((int) $payment->id);

        $this->assertSame([$marketProfile->id, $globalProfile->id], $profiles->pluck('id')->all());
        $this->assertSame([$binding->id], $bindings->pluck('id')->all());
        $this->assertSame($binding->id, $route?->primaryBinding?->id);
        $this->assertSame('KES', $walletRule?->currency_code);
        $this->assertSame(['wallet', 'payment_link'], $subscriptionRule?->activation_method_json);
        $this->assertSame($decision->id, $latestDecision?->id);
        $this->assertSame($market->id, $market->billingProviderProfiles()->count());
    }
}
