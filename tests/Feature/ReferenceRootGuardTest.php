<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\ManualPaymentBundle;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReferenceRootGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_activate_manual_flow_returns_conflict_for_existing_bundle_root(): void
    {
        [$platform, $user] = $this->createAuthContext();
        $deal = $this->createDeal($platform, 'pending');
        ManualPaymentBundle::factory()->create([
            'platform_id' => $platform->id,
            'reference_root' => 'LOCK001',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/crm/deals/{$deal->id}/activate", [
            'reason' => 'Activate via manual payment',
            'payment_method' => 'manual',
            'payment_reference' => 'LOCK001',
        ])->assertStatus(409)
            ->assertJsonPath('reference_root', 'LOCK001');
    }

    public function test_extend_manual_flow_returns_conflict_for_existing_bundle_root(): void
    {
        [$platform, $user] = $this->createAuthContext();
        $deal = $this->createDeal($platform, 'active');
        ManualPaymentBundle::factory()->create([
            'platform_id' => $platform->id,
            'reference_root' => 'LOCK002',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/crm/deals/{$deal->id}/extend", [
            'reason' => 'Extend via manual payment',
            'additional_days' => 7,
            'payment_method' => 'manual',
            'payment_reference' => 'LOCK002',
        ])->assertStatus(409)
            ->assertJsonPath('reference_root', 'LOCK002');
    }

    public function test_renew_manual_flow_returns_conflict_for_existing_bundle_root(): void
    {
        [$platform, $user] = $this->createAuthContext();
        $deal = $this->createDeal($platform, 'expired');
        ManualPaymentBundle::factory()->create([
            'platform_id' => $platform->id,
            'reference_root' => 'LOCK003',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/crm/deals/{$deal->id}/renew", [
            'reason' => 'Renew via manual payment',
            'additional_days' => 30,
            'payment_method' => 'manual',
            'payment_reference' => 'LOCK003',
        ])->assertStatus(409)
            ->assertJsonPath('reference_root', 'LOCK003');
    }

    /**
     * @return array{0: Platform, 1: User}
     */
    private function createAuthContext(): array
    {
        $platform = Platform::query()->create([
            'name' => 'Reference Guard Market',
            'domain' => 'reference-guard-' . Str::random(6) . '.example.test',
            'country' => 'Kenya',
            'timezone' => 'Africa/Nairobi',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
            'wp_api_url' => 'https://reference-guard.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        $user = User::query()->create([
            'name' => 'Reference Guard Admin',
            'email' => 'reference-guard-' . Str::random(6) . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);

        return [$platform, $user];
    }

    private function createDeal(Platform $platform, string $status): Deal
    {
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 95000 + random_int(1, 500),
            'wp_user_id' => 85000 + random_int(1, 500),
            'phone_normalized' => '254711' . random_int(100000, 999999),
        ]);

        return Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => $status,
            'amount' => 2500,
            'currency' => 'KES',
            'duration' => 'monthly',
        ]);
    }
}
