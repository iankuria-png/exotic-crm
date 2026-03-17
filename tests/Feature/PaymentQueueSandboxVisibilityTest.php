<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentQueueSandboxVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_payments_workspace_keeps_sandbox_rows_visible_but_excludes_them_from_live_summary_cards(): void
    {
        $platform = $this->createPlatform();
        $salesUser = $this->createUser($platform);

        Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '254700000301',
            'amount' => 5000,
            'currency' => 'KES',
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => 'LIVE-PAYMENT-001',
            'reference_number' => 'LIVE-PAYMENT-001',
            'status' => 'completed',
            'purpose' => 'subscription',
            'provider_environment' => 'production',
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '254700000302',
            'amount' => 9000,
            'currency' => 'KES',
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => 'SANDBOX-PAYMENT-001',
            'reference_number' => 'SANDBOX-PAYMENT-001',
            'status' => 'completed',
            'purpose' => 'subscription',
            'provider_environment' => 'sandbox',
            'payment_data' => [
                'test_mode' => true,
                'test_result' => 'completed',
                'side_effects_skipped' => true,
            ],
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        Sanctum::actingAs($salesUser);

        $response = $this->getJson('/api/crm/payments?platform_id=' . $platform->id);

        $response->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('stats_scope', 'live')
            ->assertJsonPath('stats.confirmed', 1)
            ->assertJsonPath('stats.confirmed_amount', 5000);

        $references = collect($response->json('data'))->pluck('reference_number')->all();
        $this->assertContains('LIVE-PAYMENT-001', $references);
        $this->assertContains('SANDBOX-PAYMENT-001', $references);
    }

    public function test_payments_workspace_environment_filter_can_focus_on_sandbox_or_production_rows(): void
    {
        $platform = $this->createPlatform();
        $salesUser = $this->createUser($platform);

        Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '254700000401',
            'amount' => 4200,
            'currency' => 'KES',
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => 'LIVE-PAYMENT-002',
            'reference_number' => 'LIVE-PAYMENT-002',
            'status' => 'completed',
            'purpose' => 'subscription',
            'provider_environment' => 'production',
            'created_at' => now()->subMinutes(9),
            'updated_at' => now()->subMinutes(9),
        ]);

        Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '254700000402',
            'amount' => 6100,
            'currency' => 'KES',
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => 'SANDBOX-PAYMENT-002',
            'reference_number' => 'SANDBOX-PAYMENT-002',
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

        Sanctum::actingAs($salesUser);

        $sandboxResponse = $this->getJson('/api/crm/payments?platform_id=' . $platform->id . '&environment=sandbox');
        $sandboxResponse->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('environment_filter', 'sandbox')
            ->assertJsonPath('stats_scope', 'sandbox')
            ->assertJsonPath('stats.confirmed_amount', 6100);
        $this->assertSame('SANDBOX-PAYMENT-002', $sandboxResponse->json('data.0.reference_number'));

        $productionResponse = $this->getJson('/api/crm/payments?platform_id=' . $platform->id . '&environment=production');
        $productionResponse->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('environment_filter', 'production')
            ->assertJsonPath('stats_scope', 'live')
            ->assertJsonPath('stats.confirmed_amount', 4200);
        $this->assertSame('LIVE-PAYMENT-002', $productionResponse->json('data.0.reference_number'));
    }

    private function createPlatform(): Platform
    {
        return Platform::query()->create([
            'name' => 'Sandbox Visibility Market',
            'domain' => 'sandbox-visibility-' . Str::random(6) . '.example.test',
            'country' => 'Kenya',
            'timezone' => 'Africa/Nairobi',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
            'wp_api_url' => 'https://sandbox-visibility.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function createUser(Platform $platform): User
    {
        return User::query()->create([
            'name' => 'Sales User',
            'email' => 'sales-' . Str::random(6) . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'sales',
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);
    }
}
