<?php

namespace Tests\Feature;

use App\Models\BillingSubscriptionRule;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\SubsidiaryTrialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubsidiaryTrialActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_targets_endpoint_returns_accessible_ready_markets(): void
    {
        [$mainPlatform, $targetPlatform, $deal, $user] = $this->fixture();
        $trialProduct = Product::factory()->create(['platform_id' => $targetPlatform->id]);
        BillingSubscriptionRule::query()->create([
            'market_id' => $targetPlatform->id,
            'free_trial_json' => ['enabled' => true, 'duration_days' => 9],
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/deals/{$deal->id}/subsidiary-trial-targets");

        $response->assertOk()
            ->assertJsonPath('targets.0.id', $targetPlatform->id)
            ->assertJsonPath('targets.0.free_trial_enabled', true)
            ->assertJsonPath('targets.0.free_trial_duration_days', 9)
            ->assertJsonPath('targets.0.trial_product_id', $trialProduct->id)
            ->assertJsonPath('targets.0.wp_api_ready', true)
            ->assertJsonPath('targets.0.wp_provisioning_ready', true)
            ->assertJsonPath('targets.0.config_errors', []);

        $this->assertNotContains($mainPlatform->id, collect($response->json('targets'))->pluck('id')->all());
    }

    public function test_targets_endpoint_uses_billing_free_trial_rules_not_field_sales_trial_product(): void
    {
        [, $targetPlatform, $deal, $user] = $this->fixture();
        $targetProduct = Product::factory()->create(['platform_id' => $targetPlatform->id]);
        $targetPlatform->forceFill([
            'field_trial_product_id' => null,
            'product_id' => $targetProduct->id,
        ])->save();
        BillingSubscriptionRule::query()->create([
            'market_id' => $targetPlatform->id,
            'free_trial_json' => ['enabled' => true, 'duration_days' => 14],
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/deals/{$deal->id}/subsidiary-trial-targets");

        $response->assertOk()
            ->assertJsonPath('targets.0.free_trial_enabled', true)
            ->assertJsonPath('targets.0.free_trial_duration_days', 14)
            ->assertJsonPath('targets.0.trial_product_id', $targetProduct->id)
            ->assertJsonMissingPath('targets.0.field_trial_product_id');

        $this->assertNotContains('missing_trial_product', $response->json('targets.0.config_errors'));
        $this->assertNotContains('missing_matching_product', $response->json('targets.0.config_errors'));
    }

    public function test_activation_requires_create_confirmation_when_no_subsidiary_client_is_selected(): void
    {
        [, $targetPlatform, $deal, $user] = $this->fixture();
        Product::factory()->create(['platform_id' => $targetPlatform->id]);
        BillingSubscriptionRule::query()->create([
            'market_id' => $targetPlatform->id,
            'free_trial_json' => ['enabled' => true, 'duration_days' => 7],
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/deals/{$deal->id}/activate", [
            'payment_method' => 'manual',
            'payment_reference' => 'MPESA-SUB-001',
            'subsidiary_trial' => [
                'enabled' => true,
                'platform_id' => $targetPlatform->id,
                'duration_days' => 7,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('subsidiary_trial.create_confirmed');
    }

    public function test_historical_pending_intent_cannot_activate_without_durable_authorization(): void
    {
        [, $targetPlatform, $deal, $user] = $this->fixture();
        $targetProduct = Product::factory()->create([
            'platform_id' => $targetPlatform->id,
            'tier' => 'vip',
            'weekly_price' => 0,
        ]);
        $targetPlatform->forceFill(['product_id' => $targetProduct->id])->save();
        BillingSubscriptionRule::query()->create([
            'market_id' => $targetPlatform->id,
            'free_trial_json' => ['enabled' => true, 'duration_days' => 10],
        ]);
        $targetClient = Client::factory()->create([
            'platform_id' => $targetPlatform->id,
            'phone_normalized' => '255712345678',
            'wp_post_id' => 9182,
            'wp_user_id' => 91820,
        ]);
        $deal->forceFill([
            'pending_subsidiary_trial' => [
                'status' => 'pending',
                'platform_id' => (int) $targetPlatform->id,
                'duration_days' => 10,
                'subsidiary_client_id' => (int) $targetClient->id,
                'subsidiary_client_seed' => [
                    'name' => $targetClient->name,
                    'phone_normalized' => $targetClient->phone_normalized,
                    'email' => $targetClient->email,
                    'city' => $targetClient->city,
                ],
                'requested_by_user_id' => (int) $user->id,
                'requested_at' => now()->toDateTimeString(),
                'attempt_count' => 0,
                'last_attempt_at' => null,
                'last_error' => null,
            ],
        ])->save();

        Http::fake([
            rtrim($targetPlatform->wp_api_url, '/') . "/clients/{$targetClient->wp_post_id}/activate" => Http::response(['ok' => true], 200),
            rtrim($targetPlatform->wp_api_url, '/') . "/clients/{$targetClient->wp_post_id}" => Http::response([
                'wp_post_id' => (int) $targetClient->wp_post_id,
                'wp_user_id' => (int) $targetClient->wp_user_id,
                'name' => $targetClient->name,
                'phone' => $targetClient->phone_normalized,
                'email' => $targetClient->email,
                'post_status' => 'publish',
                'premium' => false,
                'featured' => false,
            ], 200),
        ]);

        $subsidiaryDeal = app(SubsidiaryTrialService::class)->activateIfPending($deal);

        $this->assertNull($subsidiaryDeal);
        $this->assertNull($deal->fresh()->linked_deal_id);
        $this->assertDatabaseMissing('deals', ['client_id' => $targetClient->id, 'is_free_trial' => true]);
        $this->assertDatabaseMissing('payments', ['client_id' => $targetClient->id]);
        Http::assertNothingSent();
    }

    private function fixture(): array
    {
        $mainPlatform = Platform::factory()->create(['phone_prefix' => '255']);
        $targetPlatform = Platform::factory()->create(['phone_prefix' => '255']);
        $product = Product::factory()->create(['platform_id' => $mainPlatform->id]);
        $client = Client::factory()->create([
            'platform_id' => $mainPlatform->id,
            'phone_normalized' => '255712345678',
        ]);
        $deal = Deal::factory()->create([
            'platform_id' => $mainPlatform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'activated_at' => null,
            'expires_at' => null,
        ]);
        $user = User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [$mainPlatform->id, $targetPlatform->id],
        ]);

        return [$mainPlatform, $targetPlatform, $deal, $user];
    }
}
