<?php

namespace Tests\Feature\Billing;

use App\Models\BillingMarketProviderBinding;
use App\Models\BillingProviderProfile;
use App\Models\BillingRoutingRule;
use App\Models\BillingWalletRule;
use App\Models\Platform;
use App\Models\User;
use App\Services\WalletSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegacyBillingConfigProjectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_shadow_read_disabled_keeps_legacy_wallet_settings_and_credentials(): void
    {
        $platform = Platform::factory()->create([
            'currency_code' => 'KES',
        ]);

        $service = app(WalletSettingsService::class);

        $service->savePlatformConfig($platform, [
            'enabled' => false,
            'mode_override' => null,
            'currency_code' => 'KES',
            'max_single_topup' => '5000.00',
            'max_wallet_balance' => '70000.00',
            'topup_presets' => ['100.00', '250.00'],
            'allow_combined_topup_subscribe' => true,
            'show_refresh_button' => true,
            'recent_transactions_limit' => 11,
            'providers' => [
                'paystack' => [
                    'enabled' => false,
                    'min_amount' => '50.00',
                    'max_amount' => '8000.00',
                ],
            ],
        ]);

        $service->savePlatformProviderCredentials($platform, [
            'paystack' => [
                'sandbox' => [
                    'public_key' => 'pk_legacy_wallet',
                    'secret_key' => 'sk_legacy_wallet',
                ],
            ],
        ]);

        BillingWalletRule::query()->create([
            'market_id' => $platform->id,
            'enabled' => true,
            'currency_code' => 'GHS',
            'topup_preset_json' => ['150.00', '300.00'],
            'limit_json' => [
                'max_single_topup' => '9000.00',
                'max_wallet_balance' => '120000.00',
            ],
            'auto_renew_json' => ['enabled' => true],
            'ui_json' => [
                'show_refresh_button' => false,
                'allow_combined_topup_subscribe' => false,
                'recent_transactions_limit' => 7,
            ],
        ]);

        $profile = BillingProviderProfile::query()->create([
            'provider_type_key' => 'paystack',
            'profile_name' => 'Projected Paystack Sandbox',
            'country_code' => 'KE',
            'market_id' => $platform->id,
            'merchant_scope_json' => ['scope' => 'market'],
            'environment' => 'sandbox',
            'config_json' => [
                'public_key' => 'pk_projected_wallet',
            ],
            'secrets_json' => [
                'secret_key' => 'sk_projected_wallet',
            ],
            'active' => true,
        ]);

        BillingMarketProviderBinding::query()->create([
            'market_id' => $platform->id,
            'provider_profile_id' => $profile->id,
            'billing_surface' => 'wallet_funding',
            'enabled' => true,
            'operator_enabled' => true,
            'self_service_enabled' => true,
            'execution_mode' => 'direct',
            'priority' => 10,
            'fallback_group' => 'legacy-wallet',
            'restriction_json' => [
                'min_amount' => '250.00',
                'max_amount' => '9000.00',
            ],
        ]);

        config(['billing.shadow_read.enabled' => false]);

        $runtime = $service->runtimePlatformConfig($platform->fresh());

        $this->assertFalse($runtime['enabled']);
        $this->assertSame('KES', $runtime['currency_code']);
        $this->assertSame(['100.00', '250.00'], $runtime['topup_presets']);
        $this->assertFalse((bool) data_get($runtime, 'providers.paystack.enabled'));
        $this->assertSame('50.00', data_get($runtime, 'providers.paystack.min_amount'));
        $this->assertSame('sk_legacy_wallet', data_get($runtime, 'credentials.paystack.sandbox.secret_key'));
    }

    public function test_shadow_read_projects_legacy_wallet_settings_credentials_and_payment_links_from_new_rows(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin Tester',
            'email' => 'admin-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => [],
        ]);
        $platform = Platform::factory()->create([
            'name' => 'Kenya',
            'country' => 'Kenya',
            'currency_code' => 'KES',
            'payment_link_providers' => [
                'active_provider' => 'site_pay_page',
                'providers' => [
                    'site_pay_page' => [
                        'label' => 'Website Pay Page',
                        'mode' => 'static_url',
                        'enabled' => true,
                        'base_url' => 'https://market.example.test',
                        'path' => '/billing/pay',
                    ],
                ],
            ],
        ]);

        $service = app(WalletSettingsService::class);
        $service->savePlatformConfig($platform, [
            'enabled' => false,
            'currency_code' => 'KES',
            'providers' => [
                'paystack' => [
                    'enabled' => false,
                    'min_amount' => '50.00',
                    'max_amount' => '8000.00',
                ],
            ],
        ]);

        BillingWalletRule::query()->create([
            'market_id' => $platform->id,
            'enabled' => true,
            'currency_code' => 'GHS',
            'topup_preset_json' => ['150.00', '300.00', '900.00'],
            'limit_json' => [
                'max_single_topup' => '9000.00',
                'max_wallet_balance' => '120000.00',
            ],
            'auto_renew_json' => ['enabled' => true],
            'ui_json' => [
                'show_refresh_button' => false,
                'allow_combined_topup_subscribe' => false,
                'recent_transactions_limit' => 7,
            ],
        ]);

        $walletProfile = BillingProviderProfile::query()->create([
            'provider_type_key' => 'paystack',
            'profile_name' => 'Projected Paystack Sandbox',
            'country_code' => 'KE',
            'market_id' => $platform->id,
            'merchant_scope_json' => ['scope' => 'market'],
            'environment' => 'sandbox',
            'config_json' => [
                'public_key' => 'pk_projected_wallet',
            ],
            'secrets_json' => [
                'secret_key' => 'sk_projected_wallet',
            ],
            'active' => true,
        ]);

        BillingMarketProviderBinding::query()->create([
            'market_id' => $platform->id,
            'provider_profile_id' => $walletProfile->id,
            'billing_surface' => 'wallet_funding',
            'enabled' => true,
            'operator_enabled' => true,
            'self_service_enabled' => true,
            'execution_mode' => 'direct',
            'priority' => 10,
            'fallback_group' => 'legacy-wallet',
            'restriction_json' => [
                'min_amount' => '250.00',
                'max_amount' => '9000.00',
            ],
        ]);

        $proxyProfile = BillingProviderProfile::query()->create([
            'provider_type_key' => 'paystack',
            'profile_name' => 'Projected Paystack Production',
            'country_code' => 'KE',
            'market_id' => $platform->id,
            'merchant_scope_json' => ['scope' => 'market'],
            'environment' => 'production',
            'config_json' => [],
            'secrets_json' => [],
            'active' => true,
        ]);

        $proxyBinding = BillingMarketProviderBinding::query()->create([
            'market_id' => $platform->id,
            'provider_profile_id' => $proxyProfile->id,
            'billing_surface' => 'proxy_hosted_checkout',
            'enabled' => true,
            'operator_enabled' => true,
            'self_service_enabled' => true,
            'execution_mode' => 'proxy',
            'priority' => 10,
            'fallback_group' => 'checkout',
            'restriction_json' => [
                'self_checkout_fx_enabled' => true,
                'self_checkout_fx_currency' => 'KES',
                'self_checkout_fx_rate' => 11.25,
            ],
        ]);

        BillingRoutingRule::query()->create([
            'market_id' => $platform->id,
            'billing_surface' => 'proxy_hosted_checkout',
            'primary_binding_id' => $proxyBinding->id,
            'fallback_strategy_json' => ['providers' => []],
            'risk_policy_json' => ['mode' => 'proxy_preferred'],
            'active' => true,
        ]);

        config(['billing.shadow_read.enabled' => true]);

        $runtime = $service->runtimePlatformConfig($platform->fresh());

        $this->assertTrue($runtime['enabled']);
        $this->assertSame('sandbox', $runtime['mode_override']);
        $this->assertSame('GHS', $runtime['currency_code']);
        $this->assertSame('9000.00', $runtime['max_single_topup']);
        $this->assertSame('120000.00', $runtime['max_wallet_balance']);
        $this->assertSame(['150.00', '300.00', '900.00'], $runtime['topup_presets']);
        $this->assertFalse((bool) $runtime['show_refresh_button']);
        $this->assertFalse((bool) $runtime['allow_combined_topup_subscribe']);
        $this->assertSame(7, $runtime['recent_transactions_limit']);
        $this->assertTrue((bool) data_get($runtime, 'providers.paystack.enabled'));
        $this->assertSame('250.00', data_get($runtime, 'providers.paystack.min_amount'));
        $this->assertSame('9000.00', data_get($runtime, 'providers.paystack.max_amount'));
        $this->assertSame('pk_projected_wallet', data_get($runtime, 'credentials.paystack.sandbox.public_key'));
        $this->assertSame('sk_projected_wallet', data_get($runtime, 'credentials.paystack.sandbox.secret_key'));

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/settings/integrations');

        $response->assertOk()
            ->assertJsonPath('platforms.0.payment_link_providers.active_provider', 'paystack_checkout')
            ->assertJsonPath('platforms.0.payment_link_providers.providers.site_pay_page.mode', 'static_url')
            ->assertJsonPath('platforms.0.payment_link_providers.providers.paystack_checkout.mode', 'proxy_hosted_checkout')
            ->assertJsonPath('platforms.0.payment_link_providers.providers.paystack_checkout.wallet_provider_key', 'paystack')
            ->assertJsonPath('platforms.0.payment_link_providers.providers.paystack_checkout.environment', 'production')
            ->assertJsonPath('platforms.0.payment_link_providers.providers.paystack_checkout.self_checkout_fx_enabled', true)
            ->assertJsonPath('platforms.0.payment_link_providers.providers.paystack_checkout.self_checkout_fx_currency', 'KES')
            ->assertJsonPath('platforms.0.payment_link_providers.providers.paystack_checkout.self_checkout_fx_rate', 11.25);
    }
}
