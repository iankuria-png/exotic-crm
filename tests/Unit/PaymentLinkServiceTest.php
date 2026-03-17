<?php

namespace Tests\Unit;

use App\Models\Platform;
use App\Services\PaymentLinkService;
use Tests\TestCase;

class PaymentLinkServiceTest extends TestCase
{
    public function test_it_prefers_provider_direct_url(): void
    {
        $service = new PaymentLinkService();
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
        $service = new PaymentLinkService();
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
        $service = new PaymentLinkService();
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

    public function test_it_falls_back_to_domain_when_wp_api_url_is_unavailable(): void
    {
        $service = new PaymentLinkService();
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
}
