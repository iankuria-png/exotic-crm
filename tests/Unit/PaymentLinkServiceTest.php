<?php

namespace Tests\Unit;

use App\Models\BillingMarketProviderBinding;
use App\Models\BillingProviderProfile;
use App\Models\BillingRoutingRule;
use App\Models\Platform;
use App\Services\AuditService;
use App\Services\PaymentLinkService;
use App\Services\PaymentAttemptService;
use App\Services\NotificationService;
use App\Services\WalletSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prefers_provider_direct_url(): void
    {
        $service = $this->makeService();
        $platform = Platform::factory()->make([
            'payment_link_providers' => [
                'active_provider' => 'checkout',
                'providers' => [
                    'checkout' => [
                        'url' => 'https://checkout.example.test/pay/',
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            'https://checkout.example.test/pay',
            $service->resolveUrl($platform)
        );
    }

    public function test_it_builds_provider_url_from_base_url_and_path(): void
    {
        $service = $this->makeService();
        $platform = Platform::factory()->make([
            'payment_link_providers' => [
                'active_provider' => 'site_pay_page',
                'providers' => [
                    'site_pay_page' => [
                        'base_url' => 'https://market.example.test/',
                        'path' => 'billing/pay',
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            'https://market.example.test/billing/pay',
            $service->resolveUrl($platform)
        );
    }

    public function test_it_falls_back_to_wp_api_origin_when_provider_config_is_missing(): void
    {
        $service = $this->makeService();
        $platform = Platform::factory()->make([
            'wp_api_url' => 'https://crm.example.test/wp-json/exotic-crm-sync/v1',
            'domain' => 'fallback.example.test',
            'payment_link_providers' => [
                'active_provider' => 'missing_provider',
                'providers' => [],
            ],
        ]);

        $this->assertSame(
            'https://crm.example.test/pay',
            $service->resolveUrl($platform)
        );
    }

    public function test_it_returns_null_for_proxy_hosted_checkout_providers_until_proxy_links_are_implemented(): void
    {
        $service = $this->makeService();
        $platform = Platform::factory()->make([
            'payment_link_providers' => [
                'active_provider' => 'paystack_checkout',
                'providers' => [
                    'paystack_checkout' => [
                        'mode' => 'proxy_hosted_checkout',
                        'enabled' => true,
                        'wallet_provider_key' => 'paystack',
                        'environment' => 'sandbox',
                    ],
                ],
            ],
            'wp_api_url' => 'https://crm.example.test/wp-json/exotic-crm-sync/v1',
        ]);

        $this->assertNull($service->resolveUrl($platform));
    }

    public function test_it_falls_back_to_domain_when_wp_api_url_is_unavailable(): void
    {
        $service = $this->makeService();
        $platform = Platform::factory()->make([
            'wp_api_url' => null,
            'domain' => 'market.example.test',
            'payment_link_providers' => null,
        ]);

        $this->assertSame(
            'https://market.example.test/pay',
            $service->resolveUrl($platform)
        );
    }

    public function test_it_resolves_projected_provider_config_when_shadow_read_is_enabled(): void
    {
        config(['billing.shadow_read.enabled' => true]);

        $service = $this->makeService();
        $platform = Platform::factory()->create([
            'country' => 'Kenya',
            'currency_code' => 'KES',
            'payment_link_providers' => null,
        ]);

        $profile = BillingProviderProfile::query()->create([
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

        $binding = BillingMarketProviderBinding::query()->create([
            'market_id' => $platform->id,
            'provider_profile_id' => $profile->id,
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
            'primary_binding_id' => $binding->id,
            'fallback_strategy_json' => ['providers' => []],
            'risk_policy_json' => ['mode' => 'proxy_preferred'],
            'active' => true,
        ]);

        $resolved = $service->resolveProviderConfig($platform->fresh());

        $this->assertSame('paystack_checkout', $resolved['key'] ?? null);
        $this->assertSame('proxy_hosted_checkout', data_get($resolved, 'config.mode'));
        $this->assertSame('paystack', data_get($resolved, 'config.wallet_provider_key'));
        $this->assertSame('production', data_get($resolved, 'config.environment'));
        $this->assertTrue((bool) data_get($resolved, 'config.self_checkout_fx_enabled'));
    }

    private function makeService(): PaymentLinkService
    {
        return new PaymentLinkService(
            new NotificationService(),
            new PaymentAttemptService(),
            new AuditService(),
            app(\App\Services\BillingModeService::class),
            app(WalletSettingsService::class)
        );
    }
}
