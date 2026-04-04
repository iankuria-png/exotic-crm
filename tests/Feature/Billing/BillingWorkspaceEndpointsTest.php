<?php

namespace Tests\Feature\Billing;

use App\Models\BillingProviderProfile;
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
            ->assertJsonPath('profiles.0.secrets_json.api_key', '••••••••');

        $this->getJson("/api/crm/settings/billing/routing-rules/{$platform->id}")
            ->assertOk()
            ->assertJsonPath('count', 1);

        $this->getJson("/api/crm/settings/billing/wallet-rules/{$platform->id}")
            ->assertOk()
            ->assertJsonPath('wallet_rule.currency_code', 'KES');

        $this->getJson("/api/crm/settings/billing/subscription-rules/{$platform->id}")
            ->assertOk()
            ->assertJsonPath('subscription_rule.activation_method_json.payment_link', true);
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
