<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\ReportingFxRate;
use App\Models\User;
use App\Services\ClientLifetimeValueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientLifetimeValueServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sums_reportable_client_payments_with_per_date_fx(): void
    {
        config(['services.reporting_fx.enabled' => true]);

        $platform = Platform::factory()->create(['currency_code' => 'KES']);
        $client = Client::factory()->create(['platform_id' => $platform->id]);
        $noPayments = Client::factory()->create(['platform_id' => $platform->id]);

        $this->fx('KES', '2026-06-01', '0.0100000000');
        $this->fx('UGX', '2026-06-02', '0.0002500000');

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => null,
            'client_id' => $client->id,
            'amount' => 1000,
            'currency' => 'KES',
            'completed_at' => '2026-06-01 10:00:00',
            'created_at' => '2026-06-01 09:00:00',
        ]);
        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => null,
            'client_id' => $client->id,
            'amount' => 4000,
            'currency' => 'UGX',
            'completed_at' => '2026-06-02 10:00:00',
            'created_at' => '2026-06-02 09:00:00',
        ]);
        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => null,
            'client_id' => $client->id,
            'amount' => 9999,
            'currency' => 'KES',
            'purpose' => 'wallet_topup',
            'completed_at' => '2026-06-01 11:00:00',
        ]);
        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => null,
            'client_id' => $client->id,
            'amount' => 9999,
            'currency' => 'KES',
            'status' => 'failed',
            'completed_at' => '2026-06-01 12:00:00',
        ]);
        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => null,
            'client_id' => $client->id,
            'amount' => 9999,
            'currency' => 'KES',
            'record_classification' => Payment::RECORD_CLASSIFICATION_TEST,
            'completed_at' => '2026-06-01 13:00:00',
        ]);

        $values = app(ClientLifetimeValueService::class)->forClientIds([$client->id, $noPayments->id]);

        $this->assertArrayHasKey($client->id, $values);
        $this->assertArrayNotHasKey($noPayments->id, $values);
        $this->assertSame(11.0, $values[$client->id]['value_usd']);
        $this->assertSame(2, $values[$client->id]['payment_count']);
        $this->assertFalse($values[$client->id]['partial']);
        $this->assertSame(['KES' => 1000.0, 'UGX' => 4000.0], $values[$client->id]['source_breakdown']);
        $this->assertSame('2026-06-02 10:00:00', $values[$client->id]['last_payment_at']);
    }

    public function test_it_preserves_source_breakdown_when_fx_is_partial(): void
    {
        config(['services.reporting_fx.enabled' => true]);

        $platform = Platform::factory()->create(['currency_code' => 'GHS']);
        $client = Client::factory()->create(['platform_id' => $platform->id]);

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => null,
            'client_id' => $client->id,
            'amount' => 45000,
            'currency' => 'GHS',
            'completed_at' => '2026-06-01 10:00:00',
            'created_at' => '2026-06-01 09:00:00',
        ]);

        $values = app(ClientLifetimeValueService::class)->forClientIds([$client->id]);

        $this->assertNull($values[$client->id]['value_usd']);
        $this->assertTrue($values[$client->id]['partial']);
        $this->assertSame(1, $values[$client->id]['payment_count']);
        $this->assertSame(['GHS' => 45000.0], $values[$client->id]['source_breakdown']);
    }

    public function test_clients_index_exposes_lifetime_value_fields(): void
    {
        config(['services.reporting_fx.enabled' => true]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => [],
        ]);
        $platform = Platform::factory()->create(['currency_code' => 'USD']);
        $paidClient = Client::factory()->create(['platform_id' => $platform->id, 'name' => 'Paid Client']);
        $unpaidClient = Client::factory()->create(['platform_id' => $platform->id, 'name' => 'Unpaid Client']);

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => null,
            'client_id' => $paidClient->id,
            'amount' => 25,
            'currency' => 'USD',
            'completed_at' => '2026-06-01 10:00:00',
            'created_at' => '2026-06-01 09:00:00',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/crm/clients?platform_id={$platform->id}&per_page=25");

        $response->assertOk();
        $rows = collect($response->json('data'))->keyBy('id');

        $this->assertEqualsWithDelta(25.0, (float) $rows[$paidClient->id]['lifetime_value_usd'], 0.001);
        $this->assertSame(1, $rows[$paidClient->id]['lifetime_payment_count']);
        $this->assertFalse($rows[$paidClient->id]['lifetime_value_partial']);
        $this->assertEqualsWithDelta(0.0, (float) $rows[$unpaidClient->id]['lifetime_value_usd'], 0.001);
        $this->assertSame(0, $rows[$unpaidClient->id]['lifetime_payment_count']);
    }

    private function fx(string $sourceCurrency, string $date, string $rate): void
    {
        ReportingFxRate::query()->create([
            'provider' => 'currencyapi',
            'source_currency' => $sourceCurrency,
            'target_currency' => 'USD',
            'rate_date' => $date,
            'rate' => $rate,
            'fetched_at' => now(),
        ]);
    }
}
