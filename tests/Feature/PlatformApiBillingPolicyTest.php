<?php

namespace Tests\Feature;

use App\Models\BillingSubscriptionRule;
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
}
