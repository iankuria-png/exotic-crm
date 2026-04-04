<?php

namespace Tests\Feature;

use App\Mail\WalletSettingsTestMail;
use App\Models\Client;
use App\Models\IntegrationSetting;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Services\WalletSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletSettingsPhaseFourTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_wallet_system_and_response_masks_smtp_secret(): void
    {
        $admin = $this->createUser('admin');
        Sanctum::actingAs($admin);

        $response = $this->patchJson('/api/crm/settings/wallet', [
            'mode' => 'sandbox',
            'default_currency' => 'KES',
            'max_single_topup_default' => '60000.00',
            'max_wallet_balance_default' => '300000.00',
            'billing_domains' => [
                'sandbox' => 'https://sandbox-billing.example.test',
                'production' => 'https://billing.example.test',
            ],
            'billing_branding' => [
                'sandbox' => [
                    'business_name' => 'Exotic Test Billing',
                    'description' => 'Sandbox top-up flow',
                ],
                'production' => [
                    'business_name' => 'Exotic Billing',
                    'description' => 'Live top-up flow',
                ],
            ],
            'redirect_delay_seconds' => 4,
            'wallet_refresh_rate_limit_seconds' => 20,
            'wallet_refresh_timeout_seconds' => 18,
            'topup_poll_interval_seconds' => 12,
            'smtp' => [
                'enabled' => true,
                'host' => 'smtp.example.test',
                'port' => 587,
                'username' => 'wallet-user',
                'password' => 'super-secret-password',
                'encryption' => 'tls',
                'from_address' => 'wallet@example.test',
                'from_name' => 'Wallet Bot',
            ],
            'reason' => 'Configure wallet system for sandbox rollout',
        ]);

        $response->assertOk()
            ->assertJsonPath('system.mode', 'sandbox')
            ->assertJsonPath('system.default_currency', 'KES')
            ->assertJsonPath('system.smtp.host', 'smtp.example.test')
            ->assertJsonPath('system.smtp.password', '')
            ->assertJsonPath('system.smtp.password_configured', true);

        $stored = IntegrationSetting::query()
            ->where('key', WalletSettingsService::SYSTEM_SETTINGS_KEY)
            ->firstOrFail();

        $this->assertSame('sandbox', data_get($stored->value, 'mode'));
        $this->assertNotEmpty(data_get($stored->value, 'smtp.password_encrypted'));
        $this->assertNotSame('super-secret-password', data_get($stored->value, 'smtp.password_encrypted'));
    }

    public function test_settings_integrations_includes_current_wallet_and_payment_link_payloads_for_workspace_rendering(): void
    {
        $admin = $this->createUser('admin');
        $platform = $this->createPlatform('Ghana');
        config([
            'services.billing.enabled' => true,
            'services.billing.features.workspace' => true,
            'services.billing.features.diagnostics_v2' => true,
            'services.billing.provider_family.kopokopo.enabled' => true,
            'services.billing.provider_family.paystack.enabled' => false,
        ]);

        app(WalletSettingsService::class)->saveSystemConfig([
            'mode' => 'sandbox',
            'default_currency' => 'GHS',
            'billing_domains' => [
                'sandbox' => 'https://sandbox-billing.example.test',
                'production' => 'https://billing.example.test',
            ],
            'billing_branding' => [
                'sandbox' => [
                    'business_name' => 'Exotic Test Billing',
                    'description' => 'Sandbox top-up flow',
                ],
                'production' => [
                    'business_name' => 'Exotic Billing',
                    'description' => 'Live top-up flow',
                ],
            ],
            'smtp' => [
                'enabled' => true,
                'host' => 'smtp.example.test',
                'port' => 587,
                'username' => 'wallet-user',
                'password' => 'super-secret-password',
                'encryption' => 'tls',
                'from_address' => 'wallet@example.test',
                'from_name' => 'Wallet Bot',
            ],
            'reason' => 'Seed wallet workspace payload baseline',
        ], $admin->id);

        app(WalletSettingsService::class)->savePlatformConfig($platform, [
            'enabled' => true,
            'mode_override' => 'sandbox',
            'currency_code' => 'GHS',
            'max_single_topup' => '75000.00',
            'max_wallet_balance' => '250000.00',
            'topup_presets' => ['500.00', '1000.00', '2500.00'],
            'allow_combined_topup_subscribe' => true,
            'show_refresh_button' => true,
            'recent_transactions_limit' => 12,
            'providers' => [
                'pesapal' => ['enabled' => false, 'min_amount' => '100.00', 'max_amount' => '150000.00'],
                'paystack' => ['enabled' => true, 'min_amount' => '100.00', 'max_amount' => '500000.00'],
                'mpesa_stk' => ['enabled' => true, 'min_amount' => '100.00', 'max_amount' => '120000.00'],
            ],
        ]);

        app(WalletSettingsService::class)->savePlatformProviderCredentials($platform, [
            'paystack' => [
                'sandbox' => [
                    'public_key' => 'pk_test_wallet',
                    'secret_key' => 'sk_test_wallet',
                ],
            ],
            'mpesa_stk' => [
                'sandbox' => [
                    'transport' => 'django_proxy',
                    'payment_service_base_url' => 'https://payments.example.test',
                    'organization_code' => '76',
                    'callback_base_url' => 'https://callbacks.example.test',
                ],
            ],
        ], $admin->id);

        app(WalletSettingsService::class)->rotateWpCredentials($platform, 'sandbox', 'both', $admin->id);

        $platform->forceFill([
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
                    'site_pay_page' => [
                        'label' => 'Website Pay Page',
                        'mode' => 'static_url',
                        'enabled' => true,
                        'base_url' => 'https://ghana.example.test',
                        'path' => '/billing/pay',
                    ],
                ],
            ],
        ])->save();

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/settings/integrations');

        $response->assertOk()
            ->assertJsonPath('billing.enabled', true)
            ->assertJsonPath('billing.features.workspace', true)
            ->assertJsonPath('billing.features.diagnostics_v2', true)
            ->assertJsonPath('billing.provider_families.kopokopo.enabled', true)
            ->assertJsonPath('billing.provider_families.paystack.enabled', false)
            ->assertJsonPath('wallet.system.mode', 'sandbox')
            ->assertJsonPath('wallet.system.smtp.password', '')
            ->assertJsonPath('wallet.system.smtp.password_configured', true)
            ->assertJsonPath('wallet.provider_keys', WalletSettingsService::PROVIDERS)
            ->assertJsonPath('services.wallet_system.status', 'connected')
            ->assertJsonPath('services.wallet_system.mode', 'sandbox')
            ->assertJsonPath('services.wallet_system.enabled_markets', 1)
            ->assertJsonPath('platforms.0.platform_id', $platform->id)
            ->assertJsonPath('platforms.0.wallet.enabled', true)
            ->assertJsonPath('platforms.0.wallet.effective_mode', 'sandbox')
            ->assertJsonPath('platforms.0.wallet.providers.paystack.enabled', true)
            ->assertJsonPath('platforms.0.wallet.providers.mpesa_stk.enabled', true)
            ->assertJsonPath('platforms.0.wallet.credentials.paystack.sandbox.secret_key_configured', true)
            ->assertJsonPath('platforms.0.wallet.credentials.wp_to_crm.sandbox.bearer_key_configured', true)
            ->assertJsonPath('platforms.0.wallet.credentials.wp_to_crm.sandbox.hmac_configured', true)
            ->assertJsonPath('platforms.0.payment_link_providers.active_provider', 'paystack_checkout')
            ->assertJsonPath('platforms.0.payment_link_providers.providers.paystack_checkout.mode', 'proxy_hosted_checkout')
            ->assertJsonPath('platforms.0.payment_link_providers.providers.paystack_checkout.wallet_provider_key', 'paystack')
            ->assertJsonPath('platforms.0.payment_link_providers.providers.site_pay_page.mode', 'static_url')
            ->assertJsonPath('platforms.0.payment_link_providers.providers.site_pay_page.path', '/billing/pay');
    }

    public function test_sub_admin_can_update_platform_wallet_settings_and_credentials_for_owned_market(): void
    {
        $platform = $this->createPlatform('Kenya');
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 9012,
            'wp_user_id' => 7012,
        ]);
        $user = $this->createUser('sub_admin', [$platform->id]);

        app(WalletSettingsService::class)->saveSystemConfig([
            'mode' => 'sandbox',
            'default_currency' => 'KES',
            'billing_domains' => [
                'sandbox' => 'https://sandbox-billing.example.test',
                'production' => 'https://billing.example.test',
            ],
            'billing_branding' => [
                'sandbox' => [
                    'business_name' => 'Exotic Test Billing',
                    'description' => 'Sandbox top-up flow',
                ],
                'production' => [
                    'business_name' => 'Exotic Billing',
                    'description' => 'Live top-up flow',
                ],
            ],
            'smtp' => [
                'enabled' => false,
                'host' => '',
                'port' => 587,
                'username' => '',
                'password' => null,
                'encryption' => 'tls',
                'from_address' => null,
                'from_name' => '',
            ],
            'reason' => 'Seed wallet system defaults for market test',
        ], $user->id);

        Sanctum::actingAs($user);
        Http::fake([
            'https://example.test/wp-json/exotic-crm-sync/v1/clients/' . $client->wp_post_id . '/wallet-config' => Http::response([
                'success' => true,
            ], 200),
            'https://example.test/wp-json/exotic-crm-sync/v1/wallet-credentials' => Http::response([
                'success' => true,
            ], 200),
        ]);

        $walletResponse = $this->patchJson("/api/crm/settings/integrations/platforms/{$platform->id}/wallet", [
            'enabled' => true,
            'mode_override' => 'sandbox',
            'currency_code' => 'KES',
            'max_single_topup' => '75000.00',
            'max_wallet_balance' => '250000.00',
            'topup_presets' => ['500.00', '1000.00', '2500.00'],
            'allow_combined_topup_subscribe' => true,
            'show_refresh_button' => true,
            'recent_transactions_limit' => 12,
            'providers' => [
                'pesapal' => ['enabled' => true, 'min_amount' => '100.00', 'max_amount' => '150000.00'],
                'paystack' => ['enabled' => true, 'min_amount' => '100.00', 'max_amount' => '500000.00'],
                'mpesa_stk' => ['enabled' => true, 'min_amount' => '100.00', 'max_amount' => '120000.00'],
            ],
            'reason' => 'Enable wallet for Kenya market',
        ]);

        $walletResponse->assertOk()
            ->assertJsonPath('wallet.enabled', true)
            ->assertJsonPath('wallet.effective_mode', 'sandbox')
            ->assertJsonPath('wallet.providers.mpesa_stk.enabled', true)
            ->assertJsonPath('wallet.topup_presets.2', '2500.00')
            ->assertJsonPath('wallet_config_sync.status', 'synced')
            ->assertJsonPath('wallet_credentials_sync.status', 'synced')
            ->assertJsonPath('wallet_credentials_sync.credential_action', 'generated_and_pushed');

        $credentialsResponse = $this->patchJson("/api/crm/settings/integrations/platforms/{$platform->id}/wallet/providers", [
            'pesapal' => [
                'sandbox' => [
                    'consumer_key' => 'pesapal-key',
                    'consumer_secret' => 'pesapal-secret',
                    'ipn_id' => 'ipn-sandbox',
                ],
            ],
            'paystack' => [
                'sandbox' => [
                    'public_key' => 'pk_test_wallet',
                    'secret_key' => 'sk_test_wallet',
                ],
            ],
            'mpesa_stk' => [
                'sandbox' => [
                    'transport' => 'django_proxy',
                    'payment_service_base_url' => 'https://payments.example.test',
                    'organization_code' => '76',
                    'callback_base_url' => 'https://callbacks.example.test',
                ],
            ],
            'reason' => 'Store Kenya wallet credentials',
        ]);

        $credentialsResponse->assertOk()
            ->assertJsonPath('wallet.credentials.pesapal.sandbox.consumer_key_configured', true)
            ->assertJsonPath('wallet.credentials.pesapal.sandbox.consumer_secret_configured', true)
            ->assertJsonPath('wallet.credentials.paystack.sandbox.secret_key_configured', true)
            ->assertJsonPath('wallet.credentials.mpesa_stk.sandbox.transport', 'django_proxy')
            ->assertJsonPath('wallet.credentials.mpesa_stk.sandbox.payment_service_base_url', 'https://payments.example.test')
            ->assertJsonPath('wallet_credentials_sync.status', 'synced')
            ->assertJsonPath('wallet_credentials_sync.credential_action', 'pushed_existing');

        $stored = IntegrationSetting::query()
            ->where('key', 'wallet_platform_credentials_' . $platform->id)
            ->firstOrFail();

        $this->assertNotEmpty(data_get($stored->value, 'pesapal.sandbox.consumer_key_encrypted'));
        $this->assertNotEmpty(data_get($stored->value, 'paystack.sandbox.secret_key_encrypted'));
        $this->assertNotSame('sk_test_wallet', data_get($stored->value, 'paystack.sandbox.secret_key_encrypted'));
    }

    public function test_rotating_wp_credentials_reveals_plaintext_once_and_stores_hashes(): void
    {
        $platform = $this->createPlatform('Uganda');
        $user = $this->createUser('sub_admin', [$platform->id]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/settings/integrations/platforms/{$platform->id}/wallet/wp-credentials/rotate", [
            'environment' => 'sandbox',
            'credential' => 'both',
            'reason' => 'Rotate plugin credentials before QA run',
        ]);

        $response->assertOk()
            ->assertJsonPath('environment', 'sandbox')
            ->assertJsonPath('credential', 'both')
            ->assertJsonPath('platform_wallet.credentials.wp_to_crm.sandbox.bearer_key_configured', true)
            ->assertJsonPath('platform_wallet.credentials.wp_to_crm.sandbox.hmac_configured', true);

        $revealedBearer = (string) $response->json('revealed.bearer_key');
        $revealedHmac = (string) $response->json('revealed.hmac_secret');

        $stored = IntegrationSetting::query()
            ->where('key', 'wallet_platform_credentials_' . $platform->id)
            ->firstOrFail();

        $this->assertTrue(Hash::check($revealedBearer, (string) data_get($stored->value, 'wp_to_crm.sandbox.bearer_key_hash')));
        $this->assertSame(
            $revealedHmac,
            Crypt::decryptString((string) data_get($stored->value, 'wp_to_crm.sandbox.hmac_secret_encrypted'))
        );
    }

    public function test_admin_can_update_wallet_pin_and_response_remains_masked(): void
    {
        $admin = $this->createUser('admin');
        Sanctum::actingAs($admin);

        $response = $this->patchJson('/api/crm/settings/wallet/pin', [
            'pin' => '4821',
            'pin_confirmation' => '4821',
            'reason' => 'Set wallet PIN before sales QA',
        ]);

        $response->assertOk()
            ->assertJsonPath('system.pin_set', true)
            ->assertJsonPath('system.pin_hash', '');

        $this->assertNotEmpty($response->json('system.pin_last_updated_at'));

        $stored = IntegrationSetting::query()
            ->where('key', WalletSettingsService::SYSTEM_SETTINGS_KEY)
            ->firstOrFail();

        $this->assertTrue(Hash::check('4821', (string) data_get($stored->value, 'pin_hash')));
    }

    public function test_admin_can_update_free_trial_pin_and_response_remains_masked(): void
    {
        $admin = $this->createUser('admin');
        Sanctum::actingAs($admin);

        $response = $this->patchJson('/api/crm/settings/free-trial/pin', [
            'pin' => '4821',
            'pin_confirmation' => '4821',
            'reason' => 'Set free-trial PIN before sales rollout',
        ]);

        $response->assertOk()
            ->assertJsonPath('system.free_trial_pin_set', true)
            ->assertJsonPath('system.free_trial_pin_hash', '');

        $this->assertNotEmpty($response->json('system.free_trial_pin_last_updated_at'));

        $stored = IntegrationSetting::query()
            ->where('key', WalletSettingsService::SYSTEM_SETTINGS_KEY)
            ->firstOrFail();

        $this->assertTrue(Hash::check('4821', (string) data_get($stored->value, 'free_trial_pin_hash')));
    }

    public function test_non_admin_cannot_update_free_trial_pin(): void
    {
        $subAdmin = $this->createUser('sub_admin');
        Sanctum::actingAs($subAdmin);

        $this->patchJson('/api/crm/settings/free-trial/pin', [
            'pin' => '4821',
            'pin_confirmation' => '4821',
            'reason' => 'Unauthorized attempt',
        ])->assertForbidden();
    }

    public function test_admin_can_update_discount_pin_and_market_max_config(): void
    {
        $admin = $this->createUser('admin');
        $kenya = $this->createPlatform('Kenya');
        $uganda = $this->createPlatform('Uganda');
        Sanctum::actingAs($admin);

        $pinResponse = $this->patchJson('/api/crm/settings/discounts/pin', [
            'pin' => '4821',
            'pin_confirmation' => '4821',
            'reason' => 'Set discount PIN before sales rollout',
        ]);

        $pinResponse->assertOk()
            ->assertJsonPath('system.discount_pin_set', true)
            ->assertJsonPath('system.discount_pin_hash', '');

        $this->assertNotEmpty($pinResponse->json('system.discount_pin_last_updated_at'));

        $configResponse = $this->patchJson('/api/crm/settings/discounts/config', [
            'discount_config' => [
                'max_percentage_by_platform' => [
                    (string) $kenya->id => 35,
                    (string) $uganda->id => 20,
                ],
            ],
            'reason' => 'Set market discount guardrails',
        ]);

        $configResponse->assertOk()
            ->assertJsonPath("system.discount_config.max_percentage_by_platform.{$kenya->id}", 35)
            ->assertJsonPath("system.discount_config.max_percentage_by_platform.{$uganda->id}", 20);

        $stored = IntegrationSetting::query()
            ->where('key', WalletSettingsService::SYSTEM_SETTINGS_KEY)
            ->firstOrFail();

        $this->assertTrue(Hash::check('4821', (string) data_get($stored->value, 'discount_pin_hash')));
        $this->assertSame(35.0, (float) data_get($stored->value, "discount_config.max_percentage_by_platform.{$kenya->id}"));
        $this->assertSame(20.0, (float) data_get($stored->value, "discount_config.max_percentage_by_platform.{$uganda->id}"));
    }

    public function test_non_admin_cannot_update_discount_config(): void
    {
        $subAdmin = $this->createUser('sub_admin');
        $platform = $this->createPlatform('Kenya');
        Sanctum::actingAs($subAdmin);

        $this->patchJson('/api/crm/settings/discounts/config', [
            'discount_config' => [
                'max_percentage_by_platform' => [
                    (string) $platform->id => 25,
                ],
            ],
            'reason' => 'Unauthorized attempt',
        ])->assertForbidden();
    }

    public function test_wallet_provider_ssl_and_email_tests_use_configured_values(): void
    {
        $platform = $this->createPlatform('Tanzania');
        $admin = $this->createUser('admin');

        app(WalletSettingsService::class)->saveSystemConfig([
            'mode' => 'sandbox',
            'default_currency' => 'KES',
            'billing_domains' => [
                'sandbox' => 'https://billing-sandbox.example.test',
                'production' => 'https://billing.example.test',
            ],
            'billing_branding' => [
                'sandbox' => [
                    'business_name' => 'Exotic Test Billing',
                    'description' => 'Sandbox top-up flow',
                ],
                'production' => [
                    'business_name' => 'Exotic Billing',
                    'description' => 'Live top-up flow',
                ],
            ],
            'smtp' => [
                'enabled' => true,
                'host' => 'smtp.example.test',
                'port' => 587,
                'username' => 'wallet-user',
                'password' => 'mail-secret',
                'encryption' => 'tls',
                'from_address' => 'wallet@example.test',
                'from_name' => 'Wallet Bot',
            ],
            'reason' => 'Seed wallet tests',
        ], $admin->id);

        app(WalletSettingsService::class)->savePlatformProviderCredentials($platform, [
            'paystack' => [
                'sandbox' => [
                    'public_key' => 'pk_test_wallet',
                    'secret_key' => 'sk_test_wallet',
                ],
            ],
        ], $admin->id);

        Http::fake([
            'https://api.paystack.co/bank*' => Http::response([
                'status' => true,
                'message' => 'Banks returned',
            ], 200),
            'https://billing-sandbox.example.test/api/billing/health' => Http::response([
                'ok' => true,
                'service' => 'wallet_billing',
            ], 200),
            'https://billing-sandbox.example.test' => Http::response('ok', 200),
        ]);
        Mail::fake();

        Sanctum::actingAs($admin);

        $providerResponse = $this->postJson("/api/crm/settings/integrations/platforms/{$platform->id}/wallet/providers/test", [
            'provider' => 'paystack',
            'environment' => 'sandbox',
            'reason' => 'Verify paystack sandbox connectivity',
        ]);

        $providerResponse->assertOk()
            ->assertJsonPath('result.provider', 'paystack')
            ->assertJsonPath('result.ok', true)
            ->assertJsonPath('result.http_status', 200);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.paystack.co/bank?perPage=1'
            && $request->hasHeader('Authorization', 'Bearer sk_test_wallet'));

        $sslResponse = $this->postJson('/api/crm/settings/wallet/test-ssl', [
            'environment' => 'sandbox',
            'reason' => 'Verify sandbox billing SSL',
        ]);

        $sslResponse->assertOk()
            ->assertJsonPath('result.environment', 'sandbox')
            ->assertJsonPath('result.ok', true)
            ->assertJsonPath('result.http_status', 200);

        $appResponse = $this->postJson('/api/crm/settings/wallet/test-app', [
            'environment' => 'sandbox',
            'reason' => 'Verify sandbox billing app',
        ]);

        $appResponse->assertOk()
            ->assertJsonPath('result.environment', 'sandbox')
            ->assertJsonPath('result.ok', true)
            ->assertJsonPath('result.http_status', 200)
            ->assertJsonPath('result.url', 'https://billing-sandbox.example.test/api/billing/health')
            ->assertJsonPath('result.provider_response.ok', true);

        $emailResponse = $this->postJson('/api/crm/settings/wallet/test-email', [
            'to_email' => 'ops@example.test',
            'reason' => 'Verify wallet SMTP delivery',
        ]);

        $emailResponse->assertOk()
            ->assertJsonPath('result.to_email', 'ops@example.test')
            ->assertJsonPath('result.status', 'success');

        Mail::assertSent(WalletSettingsTestMail::class, function (WalletSettingsTestMail $mail) {
            return $mail->hasTo('ops@example.test');
        });
    }

    public function test_wallet_domain_test_returns_failure_when_domain_is_not_configured(): void
    {
        $admin = $this->createUser('admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/crm/settings/wallet/test-domain', [
            'environment' => 'sandbox',
            'reason' => 'Check missing domain handling',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('result.environment', 'sandbox')
            ->assertJsonPath('result.status', 'failed');
    }

    public function test_dashboard_and_reports_exclude_wallet_topups_from_subscription_revenue(): void
    {
        $platform = $this->createPlatform('Kenya');
        $admin = $this->createUser('admin');
        Sanctum::actingAs($admin);

        $this->createCompletedPayment($platform, 5000, 'subscription');
        $this->createCompletedPayment($platform, 1200, 'wallet_topup');
        Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '254700999999',
            'amount' => 2200,
            'currency' => 'KES',
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => Str::upper(Str::random(10)),
            'status' => 'completed',
            'purpose' => 'subscription',
            'provider_environment' => 'sandbox',
            'payment_data' => [
                'test_mode' => true,
                'test_result' => 'completed',
                'side_effects_skipped' => true,
            ],
            'created_at' => now()->subMinutes(4),
            'updated_at' => now()->subMinutes(4),
        ]);

        $dashboard = $this->getJson('/api/crm/dashboard?platform_id=' . $platform->id);

        $dashboard->assertOk()
            ->assertJsonPath('kpis.completed_payments_window', 1)
            ->assertJsonPath('kpis.revenue_window', 5000)
            ->assertJsonPath('kpis.wallet_topups_window', 1)
            ->assertJsonPath('kpis.wallet_topup_revenue_window', 1200);

        $reports = $this->getJson('/api/crm/reports/summary?platform_id=' . $platform->id);

        $reports->assertOk()
            ->assertJsonPath('kpis.total_revenue', 5000)
            ->assertJsonPath('kpis.wallet_topups_count', 1)
            ->assertJsonPath('kpis.wallet_topup_revenue', 1200);
    }

    private function createPlatform(string $name): Platform
    {
        return Platform::query()->create([
            'name' => $name,
            'domain' => Str::slug($name) . '-' . Str::random(6) . '.example.test',
            'country' => $name,
            'timezone' => 'Africa/Nairobi',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
            'wp_api_url' => 'https://example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function createUser(string $role, array $assignedMarketIds = []): User
    {
        return User::query()->create([
            'name' => ucfirst($role) . ' User',
            'email' => strtolower($role) . Str::random(6) . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'assigned_market_ids' => $assignedMarketIds,
            'status' => 'active',
        ]);
    }

    private function createCompletedPayment(Platform $platform, float $amount, string $purpose = 'subscription'): Payment
    {
        return Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '254700' . random_int(100000, 999999),
            'amount' => $amount,
            'currency' => 'KES',
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => Str::upper(Str::random(10)),
            'status' => 'completed',
            'purpose' => $purpose,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);
    }
}
