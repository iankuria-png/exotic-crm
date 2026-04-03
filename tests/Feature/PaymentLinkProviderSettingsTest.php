<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentLinkProviderSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_static_url_payment_link_provider_configuration(): void
    {
        $platform = Platform::factory()->create();
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/crm/settings/integrations/platforms/{$platform->id}/payment-link-providers", [
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
            'reason' => 'Switch payment links to the website pay page',
        ]);

        $response->assertOk()
            ->assertJsonPath('platform.payment_link_providers.active_provider', 'site_pay_page')
            ->assertJsonPath('platform.payment_link_providers.providers.site_pay_page.mode', 'static_url')
            ->assertJsonPath('platform.payment_link_providers.providers.site_pay_page.enabled', true)
            ->assertJsonPath('platform.payment_link_providers.providers.site_pay_page.base_url', 'https://market.example.test')
            ->assertJsonPath('platform.payment_link_providers.providers.site_pay_page.path', '/billing/pay');

        $platform->refresh();

        $this->assertSame('static_url', data_get($platform->payment_link_providers, 'providers.site_pay_page.mode'));
        $this->assertTrue((bool) data_get($platform->payment_link_providers, 'providers.site_pay_page.enabled'));
        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'entity_type' => 'platform',
            'entity_id' => $platform->id,
            'action' => 'integration_platform_update',
            'reason' => 'Switch payment links to the website pay page',
        ]);
    }

    public function test_admin_can_save_proxy_payment_link_provider_configuration(): void
    {
        $platform = Platform::factory()->create();
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/crm/settings/integrations/platforms/{$platform->id}/payment-link-providers", [
            'payment_link_providers' => [
                'active_provider' => 'paystack_checkout',
                'providers' => [
                    'paystack_checkout' => [
                        'label' => 'Paystack Checkout',
                        'mode' => 'proxy_hosted_checkout',
                        'enabled' => true,
                        'wallet_provider_key' => 'paystack',
                        'environment' => 'sandbox',
                        'self_checkout_fx_enabled' => true,
                        'self_checkout_fx_currency' => 'KES',
                        'self_checkout_fx_rate' => 11.25,
                    ],
                ],
            ],
            'reason' => 'Pilot CRM proxy checkout for sandbox verification',
        ]);

        $response->assertOk()
            ->assertJsonPath('platform.payment_link_providers.active_provider', 'paystack_checkout')
            ->assertJsonPath('platform.payment_link_providers.providers.paystack_checkout.mode', 'proxy_hosted_checkout')
            ->assertJsonPath('platform.payment_link_providers.providers.paystack_checkout.enabled', true)
            ->assertJsonPath('platform.payment_link_providers.providers.paystack_checkout.wallet_provider_key', 'paystack')
            ->assertJsonPath('platform.payment_link_providers.providers.paystack_checkout.environment', 'sandbox')
            ->assertJsonPath('platform.payment_link_providers.providers.paystack_checkout.self_checkout_fx_enabled', true)
            ->assertJsonPath('platform.payment_link_providers.providers.paystack_checkout.self_checkout_fx_currency', 'KES')
            ->assertJsonPath('platform.payment_link_providers.providers.paystack_checkout.self_checkout_fx_rate', 11.25);

        $platform->refresh();

        $this->assertTrue((bool) data_get($platform->payment_link_providers, 'providers.paystack_checkout.self_checkout_fx_enabled'));
        $this->assertSame('KES', data_get($platform->payment_link_providers, 'providers.paystack_checkout.self_checkout_fx_currency'));
        $this->assertSame(11.25, data_get($platform->payment_link_providers, 'providers.paystack_checkout.self_checkout_fx_rate'));
    }

    public function test_proxy_payment_link_provider_requires_wallet_provider_and_environment(): void
    {
        $platform = Platform::factory()->create();
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/crm/settings/integrations/platforms/{$platform->id}/payment-link-providers", [
            'payment_link_providers' => [
                'active_provider' => 'proxy_checkout',
                'providers' => [
                    'proxy_checkout' => [
                        'label' => 'Proxy Checkout',
                        'mode' => 'proxy_hosted_checkout',
                        'enabled' => true,
                    ],
                ],
            ],
            'reason' => 'Try incomplete proxy config',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'payment_link_providers.providers.proxy_checkout.wallet_provider_key',
                'payment_link_providers.providers.proxy_checkout.environment',
            ]);
    }

    public function test_proxy_payment_link_provider_currently_rejects_mpesa_stk_wallet_provider_keys(): void
    {
        $platform = Platform::factory()->create();
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/crm/settings/integrations/platforms/{$platform->id}/payment-link-providers", [
            'payment_link_providers' => [
                'active_provider' => 'mpesa_proxy',
                'providers' => [
                    'mpesa_proxy' => [
                        'label' => 'M-Pesa Proxy',
                        'mode' => 'proxy_hosted_checkout',
                        'enabled' => true,
                        'wallet_provider_key' => 'mpesa_stk',
                        'environment' => 'sandbox',
                    ],
                ],
            ],
            'reason' => 'Attempt to route proxy links through M-Pesa',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'payment_link_providers.providers.mpesa_proxy.wallet_provider_key',
            ]);

        $this->assertNull($platform->fresh()->payment_link_providers);
    }

    public function test_proxy_payment_link_provider_requires_currency_and_rate_when_fx_override_is_enabled(): void
    {
        $platform = Platform::factory()->create();
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/crm/settings/integrations/platforms/{$platform->id}/payment-link-providers", [
            'payment_link_providers' => [
                'active_provider' => 'proxy_checkout',
                'providers' => [
                    'proxy_checkout' => [
                        'label' => 'Proxy Checkout',
                        'mode' => 'proxy_hosted_checkout',
                        'enabled' => true,
                        'wallet_provider_key' => 'paystack',
                        'environment' => 'production',
                        'self_checkout_fx_enabled' => true,
                    ],
                ],
            ],
            'reason' => 'Try incomplete FX override config',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'payment_link_providers.providers.proxy_checkout.self_checkout_fx_currency',
                'payment_link_providers.providers.proxy_checkout.self_checkout_fx_rate',
            ]);
    }

    public function test_legacy_payment_link_provider_entries_default_to_static_url_and_enabled(): void
    {
        $platform = Platform::factory()->create();
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/crm/settings/integrations/platforms/{$platform->id}/payment-link-providers", [
            'payment_link_providers' => [
                'active_provider' => 'legacy_page',
                'providers' => [
                    'legacy_page' => [
                        'label' => 'Legacy Page',
                        'url' => 'https://legacy.example.test/pay',
                    ],
                ],
            ],
            'reason' => 'Normalize legacy payment link entries',
        ]);

        $response->assertOk()
            ->assertJsonPath('platform.payment_link_providers.providers.legacy_page.mode', 'static_url')
            ->assertJsonPath('platform.payment_link_providers.providers.legacy_page.enabled', true)
            ->assertJsonPath('platform.payment_link_providers.providers.legacy_page.url', 'https://legacy.example.test/pay');
    }

    private function createAdmin(): User
    {
        return User::query()->create([
            'name' => 'Admin Tester',
            'email' => 'admin-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => [],
        ]);
    }
}
