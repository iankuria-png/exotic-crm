<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTimingInstrumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_summary_emits_timing_checkpoints_for_all_markets_requests(): void
    {
        Log::spy();

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'email' => Str::random(8) . '@example.test',
        ]);
        Sanctum::actingAs($admin);

        $kenya = Platform::factory()->create([
            'name' => 'Kenya',
            'country' => 'Kenya',
            'currency_code' => 'KES',
        ]);
        $ghana = Platform::factory()->create([
            'name' => 'Ghana',
            'country' => 'Ghana',
            'currency_code' => 'GHS',
        ]);

        Payment::factory()->create([
            'platform_id' => $kenya->id,
            'amount' => 1200,
            'currency' => 'KES',
            'status' => 'completed',
            'purpose' => 'subscription',
            'created_at' => '2026-04-20 09:00:00',
            'completed_at' => '2026-04-20 09:10:00',
        ]);
        Payment::factory()->create([
            'platform_id' => $ghana->id,
            'amount' => 210,
            'currency' => 'GHS',
            'status' => 'completed',
            'purpose' => 'subscription',
            'created_at' => '2026-04-20 10:00:00',
            'completed_at' => '2026-04-20 10:10:00',
        ]);

        $response = $this->getJson('/api/crm/dashboard?from=2026-04-20&to=2026-04-20&currency_mode=flat&reporting_currency=USD');

        $response->assertOk();

        foreach ([
            'resolve_oldest_dashboard_record_at',
            'normalize_revenue_windows',
            'renewal_summary',
            'retention_summary',
            'completed',
        ] as $section) {
            Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($section): bool {
                return $message === 'CRM dashboard timing checkpoint'
                    && ($context['section'] ?? null) === $section
                    && ($context['platform_scope'] ?? null) === 'all_accessible_markets'
                    && ($context['currency_mode'] ?? null) === 'flat'
                    && ($context['reporting_currency'] ?? null) === 'USD'
                    && array_key_exists('elapsed_ms', $context)
                    && array_key_exists('timings_ms', $context);
            })->once();
        }
    }
}
