<?php

namespace Tests\Feature\CRM;

use App\Services\PaymentExportDataService;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PaymentExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_export_endpoint_enforces_auth_and_roles(): void
    {
        $platform = Platform::factory()->create();
        $salesUser = User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [$platform->id],
        ]);
        $marketingUser = User::factory()->create([
            'role' => 'marketing',
            'status' => 'active',
            'assigned_market_ids' => [$platform->id],
        ]);

        $this->postJson('/api/crm/payments/export', [
            'columns' => ['id'],
        ])->assertStatus(401);

        Sanctum::actingAs($marketingUser);
        $this->postJson('/api/crm/payments/export', [
            'platform_id' => $platform->id,
            'columns' => ['id'],
        ])->assertStatus(403);

        Sanctum::actingAs($salesUser);
        $this->postJson('/api/crm/payments/export', [
            'platform_id' => $platform->id,
            'columns' => ['id'],
        ])->assertOk();
    }

    public function test_export_uses_the_same_filtered_row_scope_as_payments_page(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'KES']);
        $product = Product::factory()->create(['platform_id' => $platform->id, 'name' => 'VIP']);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'created_at' => Carbon::parse('2026-05-02 09:00:00'),
        ]);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'discount_percentage' => 10,
            'subscription_lifecycle' => 'renewal',
            'status' => 'active',
            'activated_at' => Carbon::parse('2026-05-02 09:00:00'),
            'expires_at' => Carbon::parse('2026-06-02 09:00:00'),
        ]);

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'deal_id' => $deal->id,
            'client_id' => $client->id,
            'transaction_reference' => 'MATCH-001',
            'amount' => 500,
            'currency' => 'KES',
            'status' => 'completed',
            'source' => 'gateway',
            'provider_environment' => 'production',
            'match_confidence' => 'manual',
            'reconciliation_confidence' => 'high',
            'reconciliation_state' => 'resolved',
            'purpose' => 'subscription',
            'created_at' => Carbon::parse('2026-05-03 10:00:00'),
            'completed_at' => Carbon::parse('2026-05-03 10:05:00'),
        ]);

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'transaction_reference' => 'MISS-001',
            'amount' => 700,
            'currency' => 'KES',
            'status' => 'failed',
            'source' => 'gateway',
            'provider_environment' => 'production',
            'purpose' => 'subscription',
            'created_at' => Carbon::parse('2026-05-03 11:00:00'),
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $payload = [
            'search' => 'MATCH-001',
            'status' => 'completed',
            'matched' => 'matched',
            'has_discount' => '1',
            'platform_id' => $platform->id,
            'source' => 'gateway',
            'environment' => 'production',
            'test_visibility' => 'include',
            'match_confidence' => 'high',
            'review_state' => 'resolved',
            'customer_mix_segment' => 'new_active',
            'from' => '2026-05-01',
            'to' => '2026-05-07',
            'currency_mode' => 'flat',
            'reporting_currency' => 'USD',
            'columns' => ['id', 'transaction_reference', 'client_name'],
        ];

        $pageResponse = $this->getJson('/api/crm/payments?' . http_build_query($payload));
        $pageResponse->assertOk()->assertJsonPath('total', 1);

        $exportResponse = $this->postJson('/api/crm/payments/export', $payload);
        $exportResponse->assertOk();

        $path = storage_path('framework/testing/payment-export-filter-parity.xlsx');
        file_put_contents($path, $exportResponse->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame(2, $sheet->getHighestRow());
        $this->assertSame('MATCH-001', $sheet->getCell('B2')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($path);
    }

    public function test_non_admin_cannot_export_test_visibility_only_rows(): void
    {
        $platform = Platform::factory()->create();
        $user = User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [$platform->id],
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/crm/payments/export', [
            'platform_id' => $platform->id,
            'test_visibility' => 'only',
            'columns' => ['id'],
        ])->assertStatus(403);
    }

    public function test_export_returns_422_when_scope_exceeds_hard_cap(): void
    {
        $platform = Platform::factory()->create();
        $user = User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [$platform->id],
        ]);

        $this->mock(PaymentExportDataService::class, function ($mock) {
            $mock->shouldReceive('build')
                ->once()
                ->andThrow(new HttpResponseException(response()->json([
                    'message' => 'Export scope exceeds 50,000 rows. Narrow the filters and try again.',
                ], 422)));
        });

        Sanctum::actingAs($user);

        $this->postJson('/api/crm/payments/export', [
            'platform_id' => $platform->id,
            'columns' => ['id'],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Export scope exceeds 50,000 rows. Narrow the filters and try again.');
    }

    public function test_export_respects_requested_column_subset(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create(['platform_id' => $platform->id]);

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'transaction_reference' => 'COL-001',
            'status' => 'completed',
            'purpose' => 'subscription',
        ]);

        $user = User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [$platform->id],
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/payments/export', [
            'platform_id' => $platform->id,
            'columns' => ['id', 'phone', 'status'],
        ]);

        $response->assertOk();

        $path = storage_path('framework/testing/payment-export-columns.xlsx');
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('ID', $sheet->getCell('A1')->getValue());
        $this->assertSame('Phone', $sheet->getCell('B1')->getValue());
        $this->assertSame('Status', $sheet->getCell('C1')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($path);
    }
}
