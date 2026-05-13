<?php

namespace Tests\Feature;

use App\Models\BillingSubscriptionRule;
use App\Models\BillingMarketProviderBinding;
use App\Models\BillingProviderProfile;
use App\Models\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformApiBillingPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_listing_exposes_versioned_billing_method_policy_contract(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Kenya',
            'country' => 'Kenya',
            'domain' => 'https://kenya.example.test',
            'currency_code' => 'KES',
        ]);

        BillingSubscriptionRule::query()->create([
            'market_id' => $platform->id,
            'activation_method_json' => ['methods' => ['manual', 'payment_link']],
            'renewal_method_json' => ['methods' => ['wallet_balance', 'payment_link'], 'wallet_auto_renew' => true],
            'free_trial_json' => ['enabled' => false],
            'discount_json' => ['enabled' => true],
            'expiry_policy_json' => ['grace_period_days' => 7],
        ]);

        $response = $this->getJson('/api/platforms');

        $response->assertOk()
            ->assertJsonPath('platforms.0.id', $platform->id)
            ->assertJsonPath('platforms.0.billing_method_policy.version', '2026-04-08')
            ->assertJsonPath('platforms.0.billing_method_policy.activation.methods', ['manual', 'payment_link'])
            ->assertJsonPath('platforms.0.billing_method_policy.renewal.methods', ['wallet_balance', 'payment_link'])
            ->assertJsonPath('platforms.0.billing_method_policy.renewal.wallet_auto_renew', false);
    }

    public function test_platform_listing_exposes_self_service_subscription_push_options_without_secrets(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Kenya',
            'country' => 'Kenya',
            'domain' => 'https://kenya.example.test',
            'currency_code' => 'KES',
        ]);

        $profile = BillingProviderProfile::query()->create([
            'provider_type_key' => 'kopokopo',
            'profile_name' => 'KopoKopo Kenya',
            'country_code' => 'KE',
            'market_id' => $platform->id,
            'environment' => 'production',
            'config_json' => [
                'base_url' => 'https://api.kopokopo.test',
                'till_number' => 'K123456',
            ],
            'secrets_json' => [
                'client_id' => 'secret-client',
                'client_secret' => 'secret-secret',
                'api_key' => 'secret-api-key',
            ],
            'active' => true,
        ]);

        $binding = BillingMarketProviderBinding::query()->create([
            'market_id' => $platform->id,
            'provider_profile_id' => $profile->id,
            'billing_surface' => 'subscription_push',
            'enabled' => true,
            'operator_enabled' => true,
            'self_service_enabled' => true,
            'execution_mode' => 'direct',
            'priority' => 1,
        ]);

        $response = $this->getJson('/api/platforms');

        $response->assertOk()
            ->assertJsonPath('platforms.0.self_service_payment_options.version', '2026-05-13')
            ->assertJsonPath('platforms.0.self_service_payment_options.subscription_push.enabled', true)
            ->assertJsonPath('platforms.0.self_service_payment_options.subscription_push.default_provider', 'kopokopo')
            ->assertJsonPath('platforms.0.self_service_payment_options.subscription_push.providers.0.provider', 'kopokopo')
            ->assertJsonPath('platforms.0.self_service_payment_options.subscription_push.providers.0.label', 'KopoKopo Kenya')
            ->assertJsonPath('platforms.0.self_service_payment_options.subscription_push.providers.0.mode', 'subscription_push')
            ->assertJsonPath('platforms.0.self_service_payment_options.subscription_push.providers.0.action_type', 'stk_pending')
            ->assertJsonPath('platforms.0.self_service_payment_options.subscription_push.providers.0.environment', 'production')
            ->assertJsonPath('platforms.0.self_service_payment_options.subscription_push.providers.0.binding_id', $binding->id)
            ->assertJsonMissingPath('platforms.0.self_service_payment_options.subscription_push.providers.0.config_json')
            ->assertJsonMissingPath('platforms.0.self_service_payment_options.subscription_push.providers.0.secrets_json')
            ->assertJsonMissing(['secret-api-key']);
    }
}
