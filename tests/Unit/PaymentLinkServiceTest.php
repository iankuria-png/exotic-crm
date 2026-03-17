<?php

namespace Tests\Unit;

use App\Models\Platform;
use App\Services\AuditService;
use App\Services\PaymentLinkService;
use App\Services\PaymentAttemptService;
use App\Services\NotificationService;
use Tests\TestCase;

class PaymentLinkServiceTest extends TestCase
{
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

    private function makeService(): PaymentLinkService
    {
        return new PaymentLinkService(
            new NotificationService(),
            new PaymentAttemptService(),
            new AuditService(),
            app(\App\Services\BillingModeService::class)
        );
    }
}
