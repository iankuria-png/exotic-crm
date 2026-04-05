<?php

namespace Tests\Feature\Billing;

use App\Models\IntegrationSetting;
use App\Models\Platform;
use App\Services\BillingModeService;
use App\Services\WalletSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingModeServiceDarajaTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_context_resolves_daraja_against_mpesa_runtime_credentials(): void
    {
        $platform = Platform::factory()->create([
            'currency_code' => 'KES',
            'wallet_settings' => [
                'mode_override' => 'sandbox',
                'providers' => [
                    'mpesa_stk' => [
                        'enabled' => true,
                        'min_amount' => '100.00',
                        'max_amount' => '120000.00',
                    ],
                ],
            ],
        ]);

        app(WalletSettingsService::class)->savePlatformProviderCredentials($platform, [
            'mpesa_stk' => [
                'sandbox' => [
                    'transport' => 'django_proxy',
                    'payment_service_base_url' => 'https://payments.example.test',
                    'organization_code' => '76',
                    'callback_base_url' => 'https://crm.example.test',
                ],
            ],
        ]);

        $context = app(BillingModeService::class)->providerContext($platform->fresh(), 'daraja', false, 'sandbox');

        $this->assertSame('daraja', $context['provider']);
        $this->assertSame('mpesa_stk', $context['provider_runtime_key']);
        $this->assertSame('daraja', $context['provider_definition']?->key);
        $this->assertSame('django_proxy', data_get($context, 'provider_credentials.transport'));
        $this->assertSame('https://payments.example.test', data_get($context, 'provider_credentials.payment_service_base_url'));
    }

    public function test_provider_context_accepts_kopokopo_direct_provider_when_stored_config_is_ready(): void
    {
        IntegrationSetting::query()->create([
            'key' => 'kopokopo_config',
            'value' => [
                'enabled' => true,
                'base_url' => 'https://stored.kopokopo.test',
                'till_number' => 'K123456',
                'client_id' => 'stored-client',
                'client_secret' => 'stored-secret',
                'api_key' => 'stored-api-key',
            ],
        ]);

        $platform = Platform::factory()->create([
            'currency_code' => 'KES',
            'wallet_settings' => [
                'mode_override' => 'sandbox',
                'providers' => [
                    'mpesa_stk' => [
                        'enabled' => true,
                        'min_amount' => '100.00',
                        'max_amount' => '120000.00',
                    ],
                ],
            ],
        ]);

        app(WalletSettingsService::class)->savePlatformProviderCredentials($platform, [
            'mpesa_stk' => [
                'sandbox' => [
                    'transport' => 'direct_provider',
                ],
            ],
        ]);

        $context = app(BillingModeService::class)->providerContext($platform->fresh(), 'kopokopo', false, 'sandbox');

        $this->assertSame('kopokopo', $context['provider']);
        $this->assertSame('mpesa_stk', $context['provider_runtime_key']);
        $this->assertSame('direct_provider', data_get($context, 'provider_credentials.transport'));
    }
}
