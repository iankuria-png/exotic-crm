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
            ->assertJsonPath('stats.confirmed_currency_count', 1);
        $this->assertSame(5000.0, (float) $response->json('stats.confirmed_amount'));
        $this->assertSame(5000.0, (float) $response->json('stats.confirmed_amount_breakdown.KES'));

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

    public function test_stats_breakdown_is_per_currency_and_scalar_is_null_when_mixed(): void
    {
        $platform = $this->createPlatform();
        $salesUser = $this->createUser($platform);

        // Two live completed payments with different currencies on the same platform
        Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '254700000501',
            'amount' => 5000,
            'currency' => 'KES',
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => 'MIXED-LIVE-KES-001',
            'reference_number' => 'MIXED-LIVE-KES-001',
            'status' => 'completed',
            'purpose' => 'subscription',
            'provider_environment' => 'production',
            'created_at' => now()->subMinutes(15),
            'updated_at' => now()->subMinutes(15),
        ]);

        Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '233743394455',
            'amount' => 380,
            'currency' => 'GHS',
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => 'MIXED-LIVE-GHS-001',
            'reference_number' => 'MIXED-LIVE-GHS-001',
            'status' => 'completed',
            'purpose' => 'subscription',
            'provider_environment' => 'production',
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        // Sandbox payment — must not appear in live stats
        Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '254700000502',
            'amount' => 9999,
            'currency' => 'KES',
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => 'MIXED-SANDBOX-001',
            'reference_number' => 'MIXED-SANDBOX-001',
            'status' => 'completed',
            'purpose' => 'subscription',
            'provider_environment' => 'sandbox',
            'payment_data' => ['test_mode' => true, 'test_result' => 'completed', 'side_effects_skipped' => true],
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        Sanctum::actingAs($salesUser);

        $response = $this->getJson('/api/crm/payments?platform_id=' . $platform->id);

        $response->assertOk()
            ->assertJsonPath('stats_scope', 'live')
            // Counts are always correct regardless of currency
            ->assertJsonPath('stats.confirmed', 2)
            // Mixed scope: scalar must be null so the UI cannot show a fake total
            ->assertJsonPath('stats.confirmed_amount', null)
            ->assertJsonPath('stats.confirmed_currency_count', 2)
            // Sandbox row still appears in the row list
            ->assertJsonPath('total', 3);
        // Per-currency breakdown has exact values
        $this->assertSame(5000.0, (float) $response->json('stats.confirmed_amount_breakdown.KES'));
        $this->assertSame(380.0, (float) $response->json('stats.confirmed_amount_breakdown.GHS'));
    }

    public function test_null_payment_currency_falls_back_to_platform_currency_in_breakdowns(): void
    {
        $platform = $this->createPlatform('Ghana', 'GHS');
        $salesUser = $this->createUser($platform);

        Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '233700000601',
            'amount' => 380,
            'currency' => null,
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => 'NULL-CURRENCY-GHS-001',
            'reference_number' => 'NULL-CURRENCY-GHS-001',
            'status' => 'completed',
            'purpose' => 'subscription',
            'provider_environment' => 'production',
            'created_at' => now()->subMinutes(12),
            'updated_at' => now()->subMinutes(12),
        ]);

        Sanctum::actingAs($salesUser);

        $response = $this->getJson('/api/crm/payments?platform_id=' . $platform->id);

        $response->assertOk()
            ->assertJsonPath('stats.confirmed', 1)
            ->assertJsonPath('stats.confirmed_currency_count', 1);

        $this->assertSame(380.0, (float) $response->json('stats.confirmed_amount'));
        $this->assertSame(380.0, (float) $response->json('stats.confirmed_amount_breakdown.GHS'));
        $this->assertArrayNotHasKey('KES', $response->json('stats.confirmed_amount_breakdown'));
    }

    public function test_confirmed_stats_and_completed_filter_include_expired_successful_payments(): void
    {
        $platform = $this->createPlatform();
        $salesUser = $this->createUser($platform);

        Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '254700000701',
            'amount' => 1500,
            'currency' => 'KES',
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => 'SUCCESS-COMPLETED-001',
            'reference_number' => 'SUCCESS-COMPLETED-001',
            'status' => 'completed',
            'purpose' => 'subscription',
            'provider_environment' => 'production',
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
        ]);

        Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '254700000702',
            'amount' => 59.2,
            'currency' => 'KES',
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => 'SUCCESS-EXPIRED-001',
            'reference_number' => 'SUCCESS-EXPIRED-001',
            'status' => 'expired',
            'purpose' => 'subscription',
            'provider_environment' => 'production',
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        Sanctum::actingAs($salesUser);

        $summaryResponse = $this->getJson('/api/crm/payments?platform_id=' . $platform->id);
        $summaryResponse->assertOk()
            ->assertJsonPath('stats.confirmed', 2)
            ->assertJsonPath('stats.confirmed_currency_count', 1);
        $this->assertSame(1559.2, (float) $summaryResponse->json('stats.confirmed_amount'));

        $completedFilterResponse = $this->getJson('/api/crm/payments?platform_id=' . $platform->id . '&status=completed');
        $completedFilterResponse->assertOk()
            ->assertJsonPath('total', 2);
        $completedReferences = collect($completedFilterResponse->json('data'))->pluck('reference_number')->all();
        $this->assertContains('SUCCESS-COMPLETED-001', $completedReferences);
        $this->assertContains('SUCCESS-EXPIRED-001', $completedReferences);

        $expiredFilterResponse = $this->getJson('/api/crm/payments?platform_id=' . $platform->id . '&status=expired');
        $expiredFilterResponse->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.reference_number', 'SUCCESS-EXPIRED-001');
    }

    private function createPlatform(string $country = 'Kenya', string $currencyCode = 'KES'): Platform
    {
        return Platform::query()->create([
            'name' => $country . ' Sandbox Visibility Market',
            'domain' => 'sandbox-visibility-' . Str::random(6) . '.example.test',
            'country' => $country,
            'timezone' => 'Africa/Nairobi',
            'phone_prefix' => '254',
            'currency_code' => $currencyCode,
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
