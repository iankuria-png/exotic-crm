<?php

namespace Tests\Feature;

use App\Models\BillingMarketProviderBinding;
use App\Models\BillingProviderProfile;
use App\Models\BillingRoutingRule;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentsWorkspaceLegacyOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_payments_index_no_longer_includes_legacy_operations_catalog(): void
    {
        $platform = Platform::factory()->create();
        $user = User::factory()->create([
            'role' => 'admin',
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/payments?platform_id=' . $platform->id);

        $response->assertOk()
            ->assertJsonMissingPath('legacy_operations')
            ->assertJsonMissingPath('legacy_operations_summary');
    }

    public function test_payment_diagnostics_includes_legacy_operations_catalog(): void
    {
        $platform = Platform::factory()->create();
        $user = User::factory()->create([
            'role' => 'admin',
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);

        $payment = \App\Models\Payment::factory()->create([
            'platform_id' => $platform->id,
            'status' => 'pending',
            'amount' => 1500,
            'currency' => 'KES',
            'phone' => '254700000111',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/payments/{$payment->id}/diagnostics");

        $response->assertOk()
            ->assertJsonPath('legacy_operations_summary.preserved', 7)
            ->assertJsonFragment([
                'key' => 'retry_stk',
                'disposition' => 'preserved',
            ])
            ->assertJsonFragment([
                'key' => 'direct_proxy_token_handling',
                'disposition' => 'retired',
            ]);
    }

    public function test_payments_index_projects_subscription_link_providers_when_legacy_config_is_missing(): void
    {
        $platform = Platform::factory()->create([
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'payment_link_providers' => null,
        ]);
        $user = User::factory()->create([
            'role' => 'admin',
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);
        $profile = BillingProviderProfile::query()->create([
            'provider_type_key' => 'pawapay',
            'profile_name' => 'Kenya PawaPay',
            'country_code' => 'KE',
            'market_id' => $platform->id,
            'merchant_scope_json' => ['scope' => 'market'],
            'environment' => 'production',
            'config_json' => [
                'base_url' => 'https://api.pawapay.io',
                'callback_base_url' => 'https://testing.example.test',
            ],
            'secrets_json' => [
                'api_key' => 'pawapay-production-key',
            ],
            'active' => true,
        ]);
        $binding = BillingMarketProviderBinding::query()->create([
            'market_id' => $platform->id,
            'provider_profile_id' => $profile->id,
            'billing_surface' => 'subscription_link',
            'enabled' => true,
            'operator_enabled' => true,
            'self_service_enabled' => false,
            'execution_mode' => 'direct',
            'priority' => 10,
            'fallback_group' => 'subscription-link',
            'restriction_json' => [],
        ]);
        BillingRoutingRule::query()->create([
            'market_id' => $platform->id,
            'billing_surface' => 'subscription_link',
            'primary_binding_id' => $binding->id,
            'fallback_strategy_json' => ['providers' => []],
            'risk_policy_json' => ['mode' => 'direct'],
            'active' => true,
        ]);
        Payment::factory()->create([
            'platform_id' => $platform->id,
            'status' => 'initiated',
            'amount' => 1500,
            'currency' => 'KES',
            'phone' => '254700000111',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/payments?platform_id=' . $platform->id);

        $response->assertOk()
            ->assertJsonPath('data.0.platform.payment_link_providers.active_provider', 'pawapay_checkout')
            ->assertJsonPath('data.0.platform.payment_link_providers.providers.pawapay_checkout.wallet_provider_key', 'pawapay')
            ->assertJsonPath('data.0.platform.payment_link_providers.providers.pawapay_checkout.billing_surface', 'subscription_link');
    }
}
