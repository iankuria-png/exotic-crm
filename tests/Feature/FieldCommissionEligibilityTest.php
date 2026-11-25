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
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FieldCommissionEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public static function ineligibleStaff(): array
    {
        return [
            ['sales', 'active'], ['admin', 'active'], ['sub_admin', 'active'],
            ['marketing', 'active'], ['field_sales', 'inactive'],
        ];
    }

    /** @dataProvider ineligibleStaff */
    public function test_platform_membership_and_stale_attribution_do_not_allow_accrual(string $role, string $status): void
    {
        $platform = Platform::factory()->create();
        $agent = User::factory()->create(['role' => $role, 'status' => $status, 'assigned_market_ids' => [$platform->id]]);
        $agent->platforms()->sync([$platform->id]);
        $client = Client::factory()->create(['platform_id' => $platform->id, 'created_by' => $agent->id, 'assigned_to' => $agent->id]);
        $trial = Deal::factory()->create(['client_id' => $client->id, 'platform_id' => $platform->id, 'is_free_trial' => true,
            'activated_by_field_agent' => $agent->id, 'activated_at' => now()->subDays(3)]);
        $deal = Deal::factory()->create(['client_id' => $client->id, 'platform_id' => $platform->id, 'is_free_trial' => false,
            'status' => 'active', 'amount' => 1000, 'activated_by_field_agent' => null, 'activated_at' => now()->subDays(2)]);
        $service = app(CommissionService::class);
        $this->assertFalse($service->isEligibleFieldAgent($agent->id));
        $this->assertNull($service->resolveFieldAgentForClient($client));
        $this->assertNull($service->recordActivationCommission($deal));
        $this->assertNull($deal->fresh()->activated_by_field_agent, 'Denied historical trial attribution must not be saved.');
        $deal->forceFill(['activated_by_field_agent' => $agent->id])->save();
        $this->assertNull($service->recordActivationCommission($deal));
        $renewal = Deal::factory()->create(['client_id' => $client->id, 'platform_id' => $platform->id, 'is_free_trial' => false,
            'status' => 'active', 'amount' => 1000, 'activated_by_field_agent' => null, 'activated_at' => now()]);
        $this->assertNull($service->recordRenewalCommission($renewal));
        $this->assertNull($renewal->fresh()->activated_by_field_agent);
        $this->artisan('crm:backfill-field-commissions', ['--client' => $client->id, '--apply' => true])->assertSuccessful();
        $this->assertDatabaseCount('commissions', 0);
    }

    public function test_active_field_agent_keeps_creator_assignment_and_idempotent_commissions(): void
    {
        $platform = Platform::factory()->create(['field_activation_commission_rate' => .15]);
        $field = User::factory()->create(['role' => 'field_sales', 'status' => 'active']);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $service = app(CommissionService::class);
        foreach ([$field->id, $sales->id] as $creator) {
            $client = Client::factory()->create(['platform_id' => $platform->id, 'created_by' => $creator, 'assigned_to' => $field->id]);
            $deal = Deal::factory()->create(['client_id' => $client->id, 'platform_id' => $platform->id, 'is_free_trial' => false,
                'status' => 'active', 'amount' => 1250, 'activated_at' => now(), 'activated_by_field_agent' => null]);
            $earned = $service->recordActivationCommission($deal);
            $this->assertSame($field->id, $earned->agent_user_id);
            $this->assertSame('187.50', $earned->amount);
            $this->assertSame($earned->id, $service->recordActivationCommission($deal)->id);
            $this->assertSame($field->id, $deal->fresh()->activated_by_field_agent);
        }
        $this->assertDatabaseCount('commissions', 2);
    }

    public function test_operational_rows_totals_exports_and_filters_use_only_active_field_staff(): void
    {
        $platform = Platform::factory()->create();
        $field = User::factory()->create(['role' => 'field_sales', 'status' => 'active']);
        $good = $this->earned($field, $platform);
        $hidden = [];
        foreach ([['sales','active'], ['sub_admin','active'], ['sales','inactive'], ['field_sales','inactive']] as [$role,$status]) {
            $user = User::factory()->create(['role' => $role, 'status' => $status, 'assigned_market_ids' => [$platform->id]]);
            $user->platforms()->sync([$platform->id]);
            $hidden[] = $this->earned($user, $platform);
        }
        $before = Commission::query()->orderBy('id')->get()->toArray();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $response = $this->getJson('/api/crm/admin/commissions')->assertOk();
        $response->assertJsonCount(1, 'agents')->assertJsonCount(1, 'leaderboard')->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('agents.0.id', $field->id)->assertJsonPath('data.data.0.id', $good->id);
        $response->assertJsonPath('summary.earned_by_currency.0.total', 150)->assertJsonPath('summary.this_month_count', 1);
        $response->assertJsonPath('summary.active_agents_30d', 1)->assertJsonPath('summary.funnel.acquired', 1);
        $csv = $this->get('/api/crm/admin/commissions/export')->assertOk()->streamedContent();
        $this->assertStringContainsString($field->email, $csv);
        foreach ($hidden as $row) {
            $this->assertStringNotContainsString($row->agent->email, $csv);
            $this->getJson('/api/crm/admin/commissions?agent_user_id='.$row->agent_user_id)->assertUnprocessable();
            $this->getJson('/api/crm/admin/commissions/export?agent_user_id='.$row->agent_user_id)->assertUnprocessable();
        }
        $this->getJson('/api/crm/reports/field-sales')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.agent.id', $field->id);
        $this->assertSame($before, Commission::query()->orderBy('id')->get()->toArray());
    }

    public function test_ineligible_beneficiary_and_partial_payout_selection_are_rejected_without_writes(): void
    {
        $platform = Platform::factory()->create();
        $field = User::factory()->create(['role' => 'field_sales', 'status' => 'active']);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $good = $this->earned($field, $platform);
        $other = $this->earned($sales, $platform);
        $void = $this->earned($field, $platform);
        $void->forceFill(['status' => 'void'])->save();
        $before = Commission::query()->orderBy('id')->get()->toArray();
        foreach ([[$other->id], [$good->id, $other->id], [$good->id, $void->id], [$good->id, 99999999]] as $ids) {
            try {
                app(CommissionService::class)->markPaid($ids, []);
                $this->fail('A payout containing an ineligible row must be rejected.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
            $this->assertSame($before, Commission::query()->orderBy('id')->get()->toArray());
            $this->assertDatabaseCount('commission_payouts', 0);
        }
    }

    private function earned(User $user, Platform $platform): Commission
    {
        $client = Client::factory()->create(['platform_id' => $platform->id, 'created_by' => $user->id]);
        $product = Product::query()->where('platform_id', $platform->id)->first() ?? Product::factory()->create(['platform_id' => $platform->id]);
        $deal = Deal::factory()->create(['client_id' => $client->id, 'platform_id' => $platform->id, 'product_id' => $product->id, 'status' => 'active',
            'is_free_trial' => false, 'amount' => 1000, 'activated_at' => now(), 'activated_by_field_agent' => $user->id]);
        return Commission::query()->create(['agent_user_id' => $user->id, 'client_id' => $client->id, 'deal_id' => $deal->id,
            'type' => 'activation', 'basis_amount' => 1000, 'rate' => .15, 'amount' => 150, 'currency' => 'KES', 'status' => 'earned', 'earned_at' => now()]);
    }
}
