<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentsWorkspaceLegacyOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_payments_index_no_longer_includes_legacy_operations_catalog(): void
    {
        $platform = Platform::factory()->create();
        $user = User::factory()->create([
            'role' => 'admin',
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/payments?platform_id=' . $platform->id);

        $response->assertOk()
            ->assertJsonMissingPath('legacy_operations')
            ->assertJsonMissingPath('legacy_operations_summary');
    }

    public function test_payment_diagnostics_includes_legacy_operations_catalog(): void
    {
        $platform = Platform::factory()->create();
        $user = User::factory()->create([
            'role' => 'admin',
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);

        $payment = \App\Models\Payment::factory()->create([
            'platform_id' => $platform->id,
            'status' => 'pending',
            'amount' => 1500,
            'currency' => 'KES',
            'phone' => '254700000111',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/payments/{$payment->id}/diagnostics");

        $response->assertOk()
            ->assertJsonPath('legacy_operations_summary.preserved', 7)
            ->assertJsonFragment([
                'key' => 'retry_stk',
                'disposition' => 'preserved',
            ])
            ->assertJsonFragment([
                'key' => 'direct_proxy_token_handling',
                'disposition' => 'retired',
            ]);
    }
}
