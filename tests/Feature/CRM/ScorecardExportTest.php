<?php

namespace Tests\Feature\CRM;

use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ScorecardExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_scorecard_endpoints_enforce_auth_and_roles(): void
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

        $this->getJson('/api/crm/reports/scorecard/preview')->assertStatus(401);

        Sanctum::actingAs($marketingUser);
        $this->getJson('/api/crm/reports/scorecard/preview', [
            'platform_id' => $platform->id,
        ])->assertStatus(403);

        Sanctum::actingAs($salesUser);
        $this->getJson('/api/crm/reports/scorecard/preview', [
            'platform_id' => $platform->id,
        ])->assertOk();
    }

    public function test_preview_returns_only_requested_sections(): void
    {
        $platform = Platform::factory()->create();
        $user = $this->actingSalesUser([$platform->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/reports/scorecard/preview?' . http_build_query([
            'platform_id' => $platform->id,
            'sections' => ['revenue', 'daily_peak'],
            'from' => '2026-05-01',
            'to' => '2026-05-07',
        ]));

        $response->assertOk();
        $this->assertSame(['revenue', 'daily_peak'], array_keys($response->json('sections')));
    }

    public function test_preview_uses_payment_then_deal_lifecycle_coalesce_for_revenue_split(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'USD']);
        $client = \App\Models\Client::factory()->create(['platform_id' => $platform->id]);
        $user = $this->actingSalesUser([$platform->id]);

        $renewalDeal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'subscription_lifecycle' => 'renewal',
        ]);

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'deal_id' => $renewalDeal->id,
            'amount' => 250,
            'currency' => 'USD',
            'subscription_lifecycle' => null,
            'status' => 'completed',
            'purpose' => 'subscription',
            'created_at' => Carbon::parse('2026-05-02 10:00:00'),
            'completed_at' => Carbon::parse('2026-05-02 10:05:00'),
            'reconciliation_state' => 'resolved',
        ]);

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'amount' => 100,
            'currency' => 'USD',
            'subscription_lifecycle' => 'new',
            'status' => 'completed',
            'purpose' => 'subscription',
            'created_at' => Carbon::parse('2026-05-03 10:00:00'),
            'completed_at' => Carbon::parse('2026-05-03 10:05:00'),
            'reconciliation_state' => 'resolved',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/reports/scorecard/preview?' . http_build_query([
            'platform_id' => $platform->id,
            'sections' => ['revenue'],
            'from' => '2026-05-01',
            'to' => '2026-05-07',
        ]));

        $response->assertOk()
            ->assertJsonPath('sections.revenue.buckets.new.breakdown.USD', 100)
            ->assertJsonPath('sections.revenue.buckets.renewal.breakdown.USD', 250);
    }

    public function test_preview_returns_top_and_low_revenue_days(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'KES']);
        $user = $this->actingSalesUser([$platform->id]);

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'amount' => 100,
            'currency' => 'KES',
            'created_at' => Carbon::parse('2026-05-01 08:00:00'),
            'completed_at' => Carbon::parse('2026-05-01 08:05:00'),
            'status' => 'completed',
            'purpose' => 'subscription',
            'reconciliation_state' => 'resolved',
        ]);
        Payment::factory()->create([
            'platform_id' => $platform->id,
            'amount' => 450,
            'currency' => 'KES',
            'created_at' => Carbon::parse('2026-05-02 08:00:00'),
            'completed_at' => Carbon::parse('2026-05-02 08:05:00'),
            'status' => 'completed',
            'purpose' => 'subscription',
            'reconciliation_state' => 'resolved',
        ]);
        Payment::factory()->create([
            'platform_id' => $platform->id,
            'amount' => 220,
            'currency' => 'KES',
            'created_at' => Carbon::parse('2026-05-03 08:00:00'),
            'completed_at' => Carbon::parse('2026-05-03 08:05:00'),
            'status' => 'completed',
            'purpose' => 'subscription',
            'reconciliation_state' => 'resolved',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/reports/scorecard/preview?' . http_build_query([
            'platform_id' => $platform->id,
            'sections' => ['daily_peak'],
            'from' => '2026-05-01',
            'to' => '2026-05-03',
        ]));

        $response->assertOk()
            ->assertJsonPath('sections.daily_peak.top_day.date', '2026-05-02')
            ->assertJsonPath('sections.daily_peak.low_day.date', '2026-05-01');
    }

    public function test_contact_mix_handles_per_platform_failures_and_export_still_completes(): void
    {
        $platformA = Platform::factory()->create([
            'name' => 'Alpha',
            'wp_api_url' => 'https://alpha.example.test/wp-json/exotic-crm-sync/v1',
        ]);
        $platformB = Platform::factory()->create([
            'name' => 'Beta',
            'wp_api_url' => 'https://beta.example.test/wp-json/exotic-crm-sync/v1',
        ]);
        $user = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        Http::fake([
            'https://alpha.example.test/wp-json/exotic-crm-sync/v1/analytics/rankings*' => Http::response([], 502),
            'https://beta.example.test/wp-json/exotic-crm-sync/v1/analytics/rankings*' => Http::response([
                'platform_contact_mix' => [
                    'phone_click' => ['total' => 4, 'percent' => 40],
                    'whatsapp_click' => ['total' => 6, 'percent' => 60],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $preview = $this->getJson('/api/crm/reports/scorecard/preview?' . http_build_query([
            'sections' => ['contact_mix'],
            'from' => '2026-05-01',
            'to' => '2026-05-07',
        ]));

        $preview->assertOk();
        $platforms = collect($preview->json('sections.contact_mix.platforms'));
        $this->assertNotNull($platforms->firstWhere('platform_id', $platformA->id)['error'] ?? null);
        $this->assertSame(4, data_get($platforms->firstWhere('platform_id', $platformB->id), 'platform_contact_mix.phone_click.total'));

        $export = $this->postJson('/api/crm/reports/scorecard/export', [
            'sections' => ['contact_mix'],
            'from' => '2026-05-01',
            'to' => '2026-05-07',
        ]);

        $export->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $export->headers->get('content-type')
        );
        $this->assertNotSame('', $export->streamedContent());
    }

    public function test_export_returns_xlsx_response(): void
    {
        $platform = Platform::factory()->create();
        $user = $this->actingSalesUser([$platform->id]);

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'amount' => 200,
            'currency' => 'KES',
            'created_at' => Carbon::parse('2026-05-01 09:00:00'),
            'completed_at' => Carbon::parse('2026-05-01 09:02:00'),
            'status' => 'completed',
            'purpose' => 'subscription',
            'reconciliation_state' => 'resolved',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/reports/scorecard/export', [
            'platform_id' => $platform->id,
            'sections' => ['revenue', 'daily_peak'],
            'from' => '2026-05-01',
            'to' => '2026-05-07',
        ]);

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type')
        );

        $path = storage_path('framework/testing/scorecard-export-test.xlsx');
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $this->assertSame('Revenue', $spreadsheet->getSheet(0)->getTitle());
        $spreadsheet->disconnectWorksheets();
        @unlink($path);
    }

    private function actingSalesUser(array $platformIds): User
    {
        return User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => $platformIds,
        ]);
    }
}
