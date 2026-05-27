<?php

namespace Tests\Feature;

use App\Models\ClientActiveSnapshot;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CeoDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_ceo_dashboard_endpoints_are_server_side_gated(): void
    {
        $admin = $this->user(['role' => 'admin', 'is_ceo' => false]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/crm/dashboard/ceo/summary')
            ->assertForbidden();

        $ceo = $this->user(['role' => 'admin', 'is_ceo' => true, 'status' => 'active']);
        Sanctum::actingAs($ceo);

        $this->getJson('/api/crm/dashboard/ceo/summary')
            ->assertOk()
            ->assertJsonPath('metrics.collected_revenue.label', 'Collected Revenue');
    }

    public function test_ceo_flag_requires_active_admin_in_auth_payload_and_api_gate(): void
    {
        $salesTaggedAsCeo = $this->user(['role' => 'sales', 'is_ceo' => true, 'status' => 'active']);
        Sanctum::actingAs($salesTaggedAsCeo);

        $this->getJson('/api/crm/me')
            ->assertOk()
            ->assertJsonPath('user.is_ceo', false);

        $this->getJson('/api/crm/dashboard/ceo/summary')
            ->assertForbidden();
    }

    public function test_revenue_uses_completed_at_date_basis_and_recent_payments_only_include_completed(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Nairobi',
            'country' => 'Kenya',
            'currency_code' => 'USD',
        ]);
        $product = Product::factory()->create(['platform_id' => $platform->id, 'currency' => 'USD']);
        $ceo = $this->user(['role' => 'admin', 'is_ceo' => true]);
        Sanctum::actingAs($ceo);

        ClientActiveSnapshot::query()->create([
            'date' => '2026-05-01',
            'platform_id' => $platform->id,
            'count' => 10,
            'created_at' => now(),
        ]);
        ClientActiveSnapshot::query()->create([
            'date' => '2026-05-10',
            'platform_id' => $platform->id,
            'count' => 12,
            'created_at' => now(),
        ]);

        $this->payment($platform, $product, [
            'amount' => 100,
            'status' => 'completed',
            'created_at' => '2026-05-09 12:00:00',
            'completed_at' => '2026-05-10 12:00:00',
        ]);
        $this->payment($platform, $product, [
            'amount' => 200,
            'status' => 'completed',
            'created_at' => '2026-05-10 12:00:00',
            'completed_at' => '2026-05-11 12:00:00',
        ]);
        $this->payment($platform, $product, [
            'amount' => 300,
            'status' => 'expired',
            'created_at' => '2026-05-10 13:00:00',
            'completed_at' => '2026-05-10 13:00:00',
        ]);

        $summary = $this->getJson('/api/crm/dashboard/ceo/summary?horizon=custom&from=2026-05-10&to=2026-05-10&reporting_currency=USD')
            ->assertOk()
            ->json();

        $this->assertSame(400.0, (float) data_get($summary, 'metrics.collected_revenue.value.normalized_total'));
        $this->assertSame(2, (int) data_get($summary, 'metrics.collected_revenue.value.payments_count'));
        $this->assertSame(12, (int) data_get($summary, 'metrics.active_clients.value.count'));

        $recent = $this->getJson('/api/crm/dashboard/ceo/recent-payments?platform_id=' . $platform->id . '&reporting_currency=USD')
            ->assertOk()
            ->json('payments');

        $this->assertCount(2, $recent);
        $this->assertSame([200.0, 100.0], array_map(fn ($payment) => (float) $payment['amount'], $recent));
    }

    public function test_market_pie_and_recent_payments_expose_channel_controls(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Accra',
            'country' => 'Ghana',
            'currency_code' => 'USD',
        ]);
        $product = Product::factory()->create(['platform_id' => $platform->id, 'currency' => 'USD']);
        $ceo = $this->user(['role' => 'admin', 'is_ceo' => true]);
        Sanctum::actingAs($ceo);

        $this->payment($platform, $product, [
            'amount' => 100,
            'source' => 'gateway',
            'provider_key' => 'pawapay',
            'completed_at' => now()->subMinutes(5),
        ]);
        $this->payment($platform, $product, [
            'amount' => 50,
            'source' => 'manual',
            'provider_key' => null,
            'completed_at' => now()->subMinutes(10),
        ]);
        $this->payment($platform, $product, [
            'amount' => 25,
            'source' => 'import',
            'provider_key' => null,
            'completed_at' => now()->subMinutes(15),
        ]);

        $pie = $this->getJson('/api/crm/dashboard/ceo/market-pie?reporting_currency=USD')
            ->assertOk()
            ->json();

        $this->assertSame(['self_service', 'manual', 'other'], array_column($pie['channels'], 'key'));
        $this->assertSame(['self_service', 'manual', 'other'], array_column($pie['markets'][0]['channels'], 'key'));

        $manual = $this->getJson('/api/crm/dashboard/ceo/recent-payments?limit=20&channel=manual&reporting_currency=USD')
            ->assertOk()
            ->assertJsonPath('limit', 20)
            ->assertJsonPath('channel_filter', 'manual')
            ->json('payments');

        $this->assertCount(1, $manual);
        $this->assertSame('Manual', $manual[0]['channel']['label']);
        $this->assertSame(50.0, (float) $manual[0]['amount']);
    }

    private function user(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'Test User',
            'email' => Str::uuid() . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
            'is_ceo' => false,
            'assigned_market_ids' => [],
        ], $overrides));
    }

    private function payment(Platform $platform, Product $product, array $overrides = []): Payment
    {
        $payment = Payment::factory()->make(array_merge([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'currency' => 'USD',
            'purpose' => 'subscription',
            'provider_environment' => null,
            'record_classification' => Payment::RECORD_CLASSIFICATION_LIVE,
            'reconciliation_state' => 'open',
            'resolution_code' => null,
            'source' => 'gateway',
        ], $overrides));

        $payment->save();

        return $payment;
    }
}
