<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Commission;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FieldSalesWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_sales_clients_are_scoped_tagged_and_filterable(): void
    {
        $platform = Platform::factory()->create();
        $agent = User::factory()->create([
            'role' => 'field_sales',
            'status' => 'active',
            'assigned_market_ids' => [$platform->id],
        ]);
        $otherAgent = User::factory()->create([
            'role' => 'field_sales',
            'status' => 'active',
            'assigned_market_ids' => [$platform->id],
        ]);

        $ownClient = Client::factory()->create([
            'platform_id' => $platform->id,
            'signup_source' => 'field',
            'created_by' => $agent->id,
            'phone_normalized' => '254700000001',
        ]);
        Client::factory()->create([
            'platform_id' => $platform->id,
            'signup_source' => 'field',
            'created_by' => $otherAgent->id,
            'phone_normalized' => '254700000001',
        ]);
        Client::factory()->create([
            'platform_id' => $platform->id,
            'signup_source' => 'crm_provisioned',
            'created_by' => $agent->id,
        ]);

        Sanctum::actingAs($agent);

        $this->getJson('/api/crm/clients?signup_source=field')
            ->assertOk()
            ->assertJsonPath('data.0.signup_source', 'field');

        $this->getJson('/api/crm/field/home')
            ->assertOk()
            ->assertJsonPath('summary.clients_created', 1)
            ->assertJsonPath('recent_clients.0.id', $ownClient->id);
    }

    public function test_commissions_are_recorded_idempotently_and_paid_by_agent_currency(): void
    {
        $platform = Platform::factory()->create([
            'field_activation_commission_rate' => 0.20,
            'field_renewal_commission_rate' => 0.05,
            'field_renewal_commission_months' => 4,
        ]);
        $agent = User::factory()->create(['role' => 'field_sales', 'status' => 'active']);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'signup_source' => 'field',
            'created_by' => $agent->id,
        ]);
        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'currency' => 'KES',
        ]);

        Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'is_free_trial' => true,
            'activated_by_field_agent' => $agent->id,
            'activated_at' => now()->subDays(3),
            'amount' => 0,
            'currency' => 'KES',
        ]);

        $paidDeal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'is_free_trial' => false,
            'activated_by_field_agent' => null,
            'activated_at' => now(),
            'amount' => 1000,
            'currency' => 'KES',
        ]);

        $service = app(CommissionService::class);
        $activation = $service->recordActivationCommission($paidDeal);
        $again = $service->recordActivationCommission($paidDeal->fresh());

        $this->assertNotNull($activation);
        $this->assertSame($activation->id, $again?->id);
        $this->assertSame($agent->id, (int) $paidDeal->fresh()->activated_by_field_agent);
        $this->assertSame('200.00', number_format((float) $activation->amount, 2, '.', ''));
        $this->assertDatabaseCount('commissions', 1);

        $renewalDeal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'is_free_trial' => false,
            'activated_by_field_agent' => null,
            'activated_at' => now()->addMonth(),
            'amount' => 500,
            'currency' => 'KES',
        ]);

        $renewal = $service->recordRenewalCommission($renewalDeal);

        $this->assertNotNull($renewal);
        $this->assertSame('25.00', number_format((float) $renewal->amount, 2, '.', ''));

        $payout = $service->markPaid(Commission::query()->pluck('id')->all(), [
            'external_reference' => 'PAYOUT-001',
            'paid_by' => $agent->id,
            'paid_at' => now(),
        ]);

        $this->assertSame('225.00', number_format((float) $payout->total_amount, 2, '.', ''));
        $this->assertSame(2, Commission::query()->where('status', 'paid')->count());
    }

    public function test_admin_can_save_field_sales_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $platform = Platform::factory()->create();
        $product = Product::factory()->create(['platform_id' => $platform->id]);

        Sanctum::actingAs($admin);

        $this->putJson('/api/crm/settings/field-sales', [
            'globals' => [
                'deposit_poll_timeout_minutes' => 15,
            ],
            'platforms' => [
                [
                    'id' => $platform->id,
                    'field_activation_deposit_minor' => 7500,
                    'field_trial_duration_days' => 10,
                    'field_trial_product_id' => $product->id,
                    'field_activation_commission_rate' => 0.18,
                    'field_renewal_commission_rate' => 0.06,
                    'field_renewal_commission_months' => 3,
                ],
            ],
        ])->assertOk();

        $fresh = $platform->fresh();
        $this->assertSame(7500, (int) $fresh->field_activation_deposit_minor);
        $this->assertSame(10, (int) $fresh->field_trial_duration_days);
        $this->assertSame($product->id, (int) $fresh->field_trial_product_id);
        $this->assertSame('0.1800', number_format((float) $fresh->field_activation_commission_rate, 4, '.', ''));
    }
}
