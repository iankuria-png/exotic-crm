<?php

namespace Tests\Feature\Billing;

use App\Billing\Support\LegacyBillingSystemProjector;
use App\Models\BillingSystemSetting;
use App\Services\WalletSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BillingSystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_system_settings_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('billing_system_settings'));
        $this->assertTrue(Schema::hasColumn('billing_system_settings', 'mode_json'));
        $this->assertTrue(Schema::hasColumn('billing_system_settings', 'discount_policy_json'));
    }

    public function test_wallet_settings_service_only_reads_billing_system_settings_when_live_read_flag_is_enabled(): void
    {
        $service = app(WalletSettingsService::class);

        $service->saveSystemConfig([
            'mode' => 'sandbox',
            'default_currency' => 'KES',
            'max_single_topup_default' => '50000.00',
            'max_wallet_balance_default' => '200000.00',
            'billing_domains' => [
                'sandbox' => 'https://legacy-sandbox.example.test',
                'production' => 'https://legacy-live.example.test',
            ],
            'billing_branding' => [
                'sandbox' => [
                    'business_name' => 'Legacy Sandbox',
                    'description' => 'Legacy sandbox description',
                ],
                'production' => [
                    'business_name' => 'Legacy Production',
                    'description' => 'Legacy production description',
                ],
            ],
            'redirect_delay_seconds' => 3,
            'wallet_refresh_rate_limit_seconds' => 15,
            'wallet_refresh_timeout_seconds' => 15,
            'topup_poll_interval_seconds' => 10,
            'smtp' => [
                'enabled' => true,
                'host' => 'smtp.legacy.test',
                'port' => 2525,
                'username' => 'legacy-user',
                'password' => 'legacy-secret',
                'encryption' => 'tls',
                'from_address' => 'legacy@example.test',
                'from_name' => 'Legacy Billing',
            ],
        ]);

        BillingSystemSetting::query()->create([
            'scope' => 'global',
            'mode_json' => [
                'enabled' => true,
                'mode' => 'production',
                'default_currency' => 'GHS',
                'max_single_topup_default' => '75000.00',
            ],
            'domain_json' => [
                'sandbox' => 'https://projected-sandbox.example.test',
                'production' => 'https://projected-live.example.test',
            ],
            'branding_json' => [
                'sandbox' => [
                    'business_name' => 'Projected Sandbox',
                    'description' => 'Projected sandbox description',
                ],
                'production' => [
                    'business_name' => 'Projected Production',
                    'description' => 'Projected production description',
                ],
            ],
            'timing_json' => [
                'redirect_delay_seconds' => 9,
                'wallet_refresh_rate_limit_seconds' => 33,
                'wallet_refresh_timeout_seconds' => 44,
                'topup_poll_interval_seconds' => 55,
            ],
            'smtp_json' => [
                'enabled' => true,
                'host' => 'smtp.projected.test',
                'port' => 465,
                'username' => 'projected-user',
                'password' => 'projected-secret',
                'encryption' => 'ssl',
                'from_address' => 'projected@example.test',
                'from_name' => 'Projected Billing',
            ],
            'pin_policy_json' => [
                'operator' => ['pin_hash' => 'operator-hash', 'last_updated_at' => '2026-04-04T10:00:00Z'],
                'free_trial' => ['pin_hash' => 'trial-hash', 'last_updated_at' => '2026-04-04T11:00:00Z'],
                'discount' => ['pin_hash' => 'discount-hash', 'last_updated_at' => '2026-04-04T12:00:00Z'],
            ],
            'discount_policy_json' => [
                'max_percentage_by_platform' => ['1' => 35],
            ],
        ]);

        config(['billing.billing_system_live_read.enabled' => false]);

        $legacy = $service->currentSystemConfig(masked: false);

        $this->assertSame('sandbox', $legacy['mode']);
        $this->assertSame('KES', $legacy['default_currency']);
        $this->assertSame('https://legacy-live.example.test', data_get($legacy, 'billing_domains.production'));
        $this->assertSame('smtp.legacy.test', data_get($legacy, 'smtp.host'));

        config(['billing.billing_system_live_read.enabled' => true]);

        $projected = $service->currentSystemConfig(masked: false);

        $this->assertSame('production', $projected['mode']);
        $this->assertSame('GHS', $projected['default_currency']);
        $this->assertSame('75000.00', $projected['max_single_topup_default']);
        $this->assertSame('https://projected-live.example.test', data_get($projected, 'billing_domains.production'));
        $this->assertSame('Projected Production', data_get($projected, 'billing_branding.production.business_name'));
        $this->assertSame(55, $projected['topup_poll_interval_seconds']);
        $this->assertSame('smtp.projected.test', data_get($projected, 'smtp.host'));
        $this->assertSame('discount-hash', $projected['discount_pin_hash']);
        $this->assertSame(['1' => 35], data_get($projected, 'discount_config.max_percentage_by_platform'));
    }

    public function test_projector_can_build_billing_system_payload_from_legacy_config(): void
    {
        $projector = app(LegacyBillingSystemProjector::class);

        $payload = $projector->payloadFromLegacy([
            'enabled' => true,
            'mode' => 'sandbox',
            'default_currency' => 'KES',
            'max_single_topup_default' => '9000.00',
            'max_wallet_balance_default' => '99000.00',
            'billing_domains' => [
                'sandbox' => 'https://legacy-sandbox.example.test',
            ],
            'billing_branding' => [
                'sandbox' => [
                    'business_name' => 'Legacy Sandbox',
                    'description' => 'Legacy description',
                ],
            ],
            'redirect_delay_seconds' => 5,
            'topup_poll_interval_seconds' => 12,
            'smtp' => [
                'enabled' => true,
                'host' => 'smtp.legacy.test',
            ],
            'pin_hash' => 'operator-hash',
            'discount_config' => [
                'max_percentage_by_platform' => ['4' => 20],
            ],
        ]);

        $this->assertSame('global', $payload['scope']);
        $this->assertSame('sandbox', data_get($payload, 'mode_json.mode'));
        $this->assertSame('https://legacy-sandbox.example.test', data_get($payload, 'domain_json.sandbox'));
        $this->assertSame('smtp.legacy.test', data_get($payload, 'smtp_json.host'));
        $this->assertSame('operator-hash', data_get($payload, 'pin_policy_json.operator.pin_hash'));
        $this->assertSame(['4' => 20], data_get($payload, 'discount_policy_json.max_percentage_by_platform'));
    }
}
