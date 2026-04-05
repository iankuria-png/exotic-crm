<?php

namespace Tests\Feature\Billing;

use App\Models\BillingProviderProfile;
use App\Models\BillingMarketProviderBinding;
use App\Models\BillingRoutingRule;
use App\Models\BillingSubscriptionRule;
use App\Models\BillingSystemSetting;
use App\Models\BillingWalletRule;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingWorkspaceEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_fetch_billing_workspace_overview_system_and_diagnostics_endpoints(): void
    {
        $platform = $this->createPlatform('Kenya');
        $platform->forceFill([
            'wallet_settings' => [
                'enabled' => true,
                'mode_override' => 'production',
            ],
        ])->save();

        $admin = $this->createUser('admin');
        Sanctum::actingAs($admin);

        $overview = $this->getJson('/api/crm/settings/billing/overview');
        $overview->assertOk()
            ->assertJsonStructure([
                'billing' => ['enabled', 'features', 'provider_families'],
                'summary' => ['billingEnabled', 'walletMode', 'totalMarkets', 'walletEnabledMarkets'],
                'markets' => [
                    ['id', 'name', 'country', 'wallet' => ['enabled', 'mode_override']],
                ],
            ])
            ->assertJsonPath('summary.totalMarkets', 1)
            ->assertJsonPath('summary.walletEnabledMarkets', 1);

        BillingSystemSetting::query()->create([
            'scope' => 'global',
            'mode_json' => ['mode' => 'sandbox', 'default_currency' => 'KES'],
            'domain_json' => ['sandbox' => 'https://sandbox.example.test'],
            'branding_json' => ['sandbox' => ['business_name' => 'Sandbox Billing']],
            'timing_json' => ['redirect_delay_seconds' => 3],
            'smtp_json' => ['enabled' => true, 'host' => 'smtp.example.test'],
            'pin_policy_json' => [],
            'discount_policy_json' => [],
        ]);

        $system = $this->getJson('/api/crm/settings/billing/system');
        $system->assertOk()
            ->assertJsonStructure([
                'system' => [
                    'mode',
                    'default_currency',
                    'billing_domains',
                    'billing_branding',
                    'timing',
                    'smtp',
                    'discount_config',
                ],
                'source' => ['live_read_enabled', 'source_of_truth'],
            ]);

        $diagnostics = $this->getJson('/api/crm/settings/billing/diagnostics-summary');
        $diagnostics->assertOk()
            ->assertJsonStructure([
                'services' => [
                    'wallet_system',
                    'kopokopo',
                    'payment_service',
                    'sendgrid',
                ],
            ]);
    }

    public function test_sales_is_blocked_from_billing_workspace_api_endpoints(): void
    {
        $platform = $this->createPlatform('Kenya');
        $sales = $this->createUser('sales', [$platform->id]);
        Sanctum::actingAs($sales);

        foreach ([
            '/api/crm/settings/billing/overview',
            '/api/crm/settings/billing/system',
            '/api/crm/settings/billing/diagnostics-summary',
            '/api/crm/settings/billing/providers-catalog',
            '/api/crm/settings/billing/provider-profiles',
        ] as $endpoint) {
            $this->getJson($endpoint)->assertForbidden();
        }
    }

    public function test_sub_admin_can_fetch_phase_three_billing_endpoints_in_scope(): void
    {
        $platform = $this->createPlatform('Kenya');
        $subAdmin = $this->createUser('sub_admin', [$platform->id]);

        $profile = BillingProviderProfile::query()->create([
            'provider_type_key' => 'kopokopo',
            'profile_name' => 'Kenya Live',
            'country_code' => 'KE',
            'market_id' => $platform->id,
            'environment' => 'production',
            'config_json' => ['callback_base_url' => 'https://example.test'],
            'secrets_json' => ['api_key' => 'secret-value'],
            'active' => true,
        ]);

        BillingRoutingRule::query()->create([
            'market_id' => $platform->id,
            'billing_surface' => 'subscription_link',
            'primary_binding_id' => null,
            'fallback_strategy_json' => ['type' => 'none'],
            'risk_policy_json' => ['mode' => 'direct'],
            'active' => true,
        ]);

        BillingWalletRule::query()->create([
            'market_id' => $platform->id,
            'enabled' => true,
            'currency_code' => 'KES',
            'topup_preset_json' => [500, 1000],
            'limit_json' => ['max_single' => 50000],
            'auto_renew_json' => ['enabled' => true],
            'ui_json' => ['wallet_funding_label' => 'Wallet funding'],
        ]);

        BillingSubscriptionRule::query()->create([
            'market_id' => $platform->id,
            'activation_method_json' => ['payment_link' => true],
            'renewal_method_json' => ['wallet' => ['enabled' => true]],
            'free_trial_json' => ['enabled' => false],
            'discount_json' => ['enabled' => true],
            'expiry_policy_json' => ['grace_days' => 1],
        ]);

        Sanctum::actingAs($subAdmin);

        $this->getJson('/api/crm/settings/billing/providers-catalog')
            ->assertOk()
            ->assertJsonStructure(['providers', 'count']);

        $this->getJson('/api/crm/settings/billing/provider-profiles')
            ->assertOk()
            ->assertJsonPath('profiles.0.profile_name', 'Kenya Live')
            ->assertJsonPath('profiles.0.secrets_json.api_key', '••••••••')
            ->assertJsonFragment(['key' => 'daraja'])
            ->assertJsonFragment(['key' => 'mpesa_stk']);

        $this->getJson("/api/crm/settings/billing/routing-rules/{$platform->id}")
            ->assertOk()
            ->assertJsonPath('count', 1);

        $this->getJson("/api/crm/settings/billing/wallet-rules/{$platform->id}")
            ->assertOk()
            ->assertJsonPath('wallet_rule.currency_code', 'KES');

        $this->getJson("/api/crm/settings/billing/subscription-rules/{$platform->id}")
            ->assertOk()
            ->assertJsonPath('subscription_rule.activation_method_json.payment_link', true)
            ->assertJsonPath('editable', false)
            ->assertJsonPath('market.id', $platform->id);
    }

    public function test_admin_can_create_provider_profile_with_schema_fields(): void
    {
        $platform = $this->createPlatform('Kenya');
        $admin = $this->createUser('admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/crm/settings/billing/provider-profiles', [
            'provider_type_key' => 'daraja',
            'profile_name' => 'Kenya Daraja Live',
            'country_code' => 'KE',
            'market_id' => $platform->id,
            'environment' => 'production',
            'active' => true,
            'fields' => [
                'consumer_key' => 'ck-live',
                'consumer_secret' => 'cs-live',
                'short_code' => '174379',
                'passkey' => 'pass-live',
                'callback_base_url' => 'https://billing.example.test',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('profile.provider_type_key', 'daraja')
            ->assertJsonPath('profile.profile_name', 'Kenya Daraja Live')
            ->assertJsonPath('profile.secret_state.consumer_secret', true)
            ->assertJsonPath('profile.secret_state.passkey', true)
            ->assertJsonPath('profile.secrets_json.consumer_secret', '••••••••');

        $this->assertDatabaseHas('billing_provider_profiles', [
            'provider_type_key' => 'daraja',
            'profile_name' => 'Kenya Daraja Live',
            'market_id' => $platform->id,
            'environment' => 'production',
        ]);
    }

    public function test_admin_can_update_provider_profile_without_overwriting_blank_secrets(): void
    {
        $platform = $this->createPlatform('Kenya');
        $admin = $this->createUser('admin');

        $profile = BillingProviderProfile::query()->create([
            'provider_type_key' => 'pawapay',
            'profile_name' => 'pawaPay Live',
            'country_code' => 'KE',
            'market_id' => $platform->id,
            'environment' => 'production',
            'config_json' => [
                'base_url' => 'https://api.pawapay.io',
                'callback_base_url' => 'https://crm.example.test',
            ],
            'secrets_json' => [
                'api_key' => 'existing-secret',
            ],
            'active' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/crm/settings/billing/provider-profiles/{$profile->id}", [
            'provider_type_key' => 'pawapay',
            'profile_name' => 'pawaPay Kenya Primary',
            'country_code' => 'KE',
            'market_id' => $platform->id,
            'environment' => 'production',
            'active' => true,
            'fields' => [
                'api_key' => '',
                'base_url' => 'https://api.sandbox.pawapay.io',
                'callback_base_url' => 'https://billing.exotic.test',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('profile.profile_name', 'pawaPay Kenya Primary')
            ->assertJsonPath('profile.secret_state.api_key', true)
            ->assertJsonPath('profile.secrets_json.api_key', '••••••••')
            ->assertJsonPath('profile.config_json.base_url', 'https://api.sandbox.pawapay.io');

        $profile->refresh();

        $this->assertSame('existing-secret', data_get($profile->secrets_json, 'api_key'));
        $this->assertSame('https://api.sandbox.pawapay.io', data_get($profile->config_json, 'base_url'));
    }

    public function test_sub_admin_cannot_create_or_update_provider_profiles(): void
    {
        $platform = $this->createPlatform('Kenya');
        $subAdmin = $this->createUser('sub_admin', [$platform->id]);
        $profile = BillingProviderProfile::query()->create([
            'provider_type_key' => 'kopokopo',
            'profile_name' => 'Kenya Live',
            'country_code' => 'KE',
            'market_id' => $platform->id,
            'environment' => 'production',
            'config_json' => ['callback_base_url' => 'https://example.test'],
            'secrets_json' => ['api_key' => 'secret-value'],
            'active' => true,
        ]);

        Sanctum::actingAs($subAdmin);

        $this->postJson('/api/crm/settings/billing/provider-profiles', [
            'provider_type_key' => 'kopokopo',
            'profile_name' => 'Blocked create',
            'country_code' => 'KE',
            'market_id' => $platform->id,
            'environment' => 'production',
            'fields' => [
                'base_url' => 'https://api.kopokopo.com',
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
                'api_key' => 'api-key',
                'till_number' => '123456',
            ],
        ])->assertForbidden();

        $this->putJson("/api/crm/settings/billing/provider-profiles/{$profile->id}", [
            'provider_type_key' => 'kopokopo',
            'profile_name' => 'Blocked update',
            'country_code' => 'KE',
            'market_id' => $platform->id,
            'environment' => 'production',
            'fields' => [
                'base_url' => 'https://api.kopokopo.com',
                'client_id' => 'client-id',
                'client_secret' => '',
                'api_key' => '',
                'till_number' => '123456',
            ],
        ])->assertForbidden();
    }

    public function test_admin_can_store_market_routing_rules_with_primary_and_fallback_profiles(): void
    {
        $platform = $this->createPlatform('Kenya');
        $admin = $this->createUser('admin');

        $primaryProfile = BillingProviderProfile::query()->create([
            'provider_type_key' => 'daraja',
            'profile_name' => 'Kenya Daraja Primary',
            'country_code' => 'KE',
            'market_id' => $platform->id,
            'environment' => 'production',
            'config_json' => ['callback_base_url' => 'https://billing.example.test'],
            'secrets_json' => ['consumer_secret' => 'secret'],
            'active' => true,
        ]);

        $fallbackProfile = BillingProviderProfile::query()->create([
            'provider_type_key' => 'pawapay',
            'profile_name' => 'Kenya pawaPay Fallback',
            'country_code' => 'KE',
            'market_id' => $platform->id,
            'environment' => 'production',
            'config_json' => ['base_url' => 'https://api.pawapay.io'],
            'secrets_json' => ['api_key' => 'secret'],
            'active' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/crm/settings/billing/routing-rules/{$platform->id}", [
            'rules' => [
                [
                    'billing_surface' => 'wallet_funding',
                    'active' => true,
                    'primary_profile_id' => $primaryProfile->id,
                    'fallback_profile_ids' => [$fallbackProfile->id],
                    'execution_mode' => 'proxy',
                    'operator_enabled' => true,
                    'self_service_enabled' => false,
                    'notes' => 'High-risk flows should pin the CRM proxy.',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('editable', true)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('routing_rules.0.billing_surface', 'wallet_funding')
            ->assertJsonPath('routing_rules.0.active', true)
            ->assertJsonPath('routing_rules.0.primary_binding.provider_profile.id', $primaryProfile->id)
            ->assertJsonPath('routing_rules.0.risk_policy_json.execution_mode', 'proxy')
            ->assertJsonPath('routing_rules.0.fallback_strategy_json.type', 'ordered_bindings')
            ->assertJsonPath('routing_rules.0.fallback_strategy_json.provider_profile_ids.0', $fallbackProfile->id);

        $this->assertDatabaseHas('billing_routing_rules', [
            'market_id' => $platform->id,
            'billing_surface' => 'wallet_funding',
            'active' => true,
        ]);

        $primaryBinding = BillingMarketProviderBinding::query()
            ->where('market_id', $platform->id)
            ->where('billing_surface', 'wallet_funding')
            ->where('provider_profile_id', $primaryProfile->id)
            ->first();

        $fallbackBinding = BillingMarketProviderBinding::query()
            ->where('market_id', $platform->id)
            ->where('billing_surface', 'wallet_funding')
            ->where('provider_profile_id', $fallbackProfile->id)
            ->first();

        $this->assertNotNull($primaryBinding);
        $this->assertNotNull($fallbackBinding);
        $this->assertSame('proxy', $primaryBinding->execution_mode);
        $this->assertSame(100, $primaryBinding->priority);
        $this->assertSame(200, $fallbackBinding->priority);
        $this->assertTrue((bool) $primaryBinding->enabled);
        $this->assertTrue((bool) $fallbackBinding->enabled);
    }

    public function test_sub_admin_cannot_store_market_routing_rules(): void
    {
        $platform = $this->createPlatform('Kenya');
        $subAdmin = $this->createUser('sub_admin', [$platform->id]);
        $profile = BillingProviderProfile::query()->create([
            'provider_type_key' => 'daraja',
            'profile_name' => 'Blocked route',
            'country_code' => 'KE',
            'market_id' => $platform->id,
            'environment' => 'production',
            'config_json' => ['callback_base_url' => 'https://billing.example.test'],
            'secrets_json' => ['consumer_secret' => 'secret'],
            'active' => true,
        ]);

        Sanctum::actingAs($subAdmin);

        $this->putJson("/api/crm/settings/billing/routing-rules/{$platform->id}", [
            'rules' => [
                [
                    'billing_surface' => 'wallet_funding',
                    'active' => true,
                    'primary_profile_id' => $profile->id,
                    'fallback_profile_ids' => [],
                    'execution_mode' => 'direct',
                    'operator_enabled' => true,
                    'self_service_enabled' => true,
                ],
            ],
        ])->assertForbidden();
    }

    public function test_admin_can_store_wallet_rules_for_a_market(): void
    {
        $platform = $this->createPlatform('Kenya');
        $admin = $this->createUser('admin');

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/crm/settings/billing/wallet-rules/{$platform->id}", [
            'enabled' => true,
            'currency_code' => 'KES',
            'topup_preset_json' => ['500.00', '1000.00', '2500.00'],
            'limit_json' => [
                'max_single_topup' => '50000.00',
                'max_wallet_balance' => '200000.00',
            ],
            'auto_renew_json' => [
                'enabled' => true,
            ],
            'ui_json' => [
                'allow_combined_topup_subscribe' => true,
                'show_refresh_button' => true,
                'recent_transactions_limit' => 6,
                'wallet_funding_label' => 'Wallet funding',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('editable', true)
            ->assertJsonPath('wallet_rule.enabled', true)
            ->assertJsonPath('wallet_rule.currency_code', 'KES')
            ->assertJsonPath('wallet_rule.topup_preset_json.0', '500.00')
            ->assertJsonPath('wallet_rule.limit_json.max_single_topup', '50000.00')
            ->assertJsonPath('wallet_rule.auto_renew_json.enabled', true)
            ->assertJsonPath('wallet_rule.ui_json.recent_transactions_limit', '6');

        $this->assertDatabaseHas('billing_wallet_rules', [
            'market_id' => $platform->id,
            'enabled' => true,
            'currency_code' => 'KES',
        ]);
    }

    public function test_sub_admin_cannot_store_wallet_rules(): void
    {
        $platform = $this->createPlatform('Kenya');
        $subAdmin = $this->createUser('sub_admin', [$platform->id]);

        Sanctum::actingAs($subAdmin);

        $this->putJson("/api/crm/settings/billing/wallet-rules/{$platform->id}", [
            'enabled' => true,
            'currency_code' => 'KES',
            'topup_preset_json' => ['500.00'],
        ])->assertForbidden();
    }

    public function test_admin_can_store_subscription_rules_for_a_market(): void
    {
        $platform = $this->createPlatform('Kenya');
        $admin = $this->createUser('admin');

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/crm/settings/billing/subscription-rules/{$platform->id}", [
            'activation_method_json' => [
                'methods' => ['manual', 'payment_link', 'stk_push'],
            ],
            'renewal_method_json' => [
                'methods' => ['wallet_balance', 'payment_link'],
                'wallet_auto_renew' => true,
            ],
            'free_trial_json' => [
                'enabled' => true,
                'duration_days' => 14,
            ],
            'discount_json' => [
                'enabled' => true,
                'max_percent' => 25,
                'requires_pin' => true,
            ],
            'expiry_policy_json' => [
                'grace_period_days' => 7,
                'suspend_after_days' => 30,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('editable', true)
            ->assertJsonPath('subscription_rule.activation_method_json.methods.0', 'manual')
            ->assertJsonPath('subscription_rule.renewal_method_json.wallet_auto_renew', true)
            ->assertJsonPath('subscription_rule.free_trial_json.duration_days', 14)
            ->assertJsonPath('subscription_rule.discount_json.max_percent', 25)
            ->assertJsonPath('subscription_rule.expiry_policy_json.grace_period_days', 7);

        $this->assertDatabaseHas('billing_subscription_rules', [
            'market_id' => $platform->id,
        ]);
    }

    public function test_sub_admin_cannot_store_subscription_rules(): void
    {
        $platform = $this->createPlatform('Kenya');
        $subAdmin = $this->createUser('sub_admin', [$platform->id]);

        Sanctum::actingAs($subAdmin);

        $this->putJson("/api/crm/settings/billing/subscription-rules/{$platform->id}", [
            'activation_method_json' => [
                'methods' => ['payment_link'],
            ],
        ])->assertForbidden();
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
}
