<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Models\WeeklyPriority;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WeeklyPriorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_priority_visible_to_assigned_sales_user(): void
    {
        [$platform] = $this->market();
        $admin = $this->user(['role' => 'admin', 'is_ceo' => true]);
        $sales = $this->user(['role' => 'sales', 'assigned_market_ids' => [$platform->id]]);

        Sanctum::actingAs($admin);
        $this->postJson('/api/crm/priorities', [
            'title' => 'Recover Nairobi failed payments',
            'description' => 'Run the payment recovery queue before Friday.',
            'audience' => 'sales',
            'platform_id' => $platform->id,
            'priority_level' => 'high',
            'due_at' => Carbon::now()->addDays(2)->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('priority.title', 'Recover Nairobi failed payments')
            ->assertJsonPath('priority.platform.id', $platform->id);

        Sanctum::actingAs($sales);
        $this->getJson('/api/crm/priorities?audience=sales')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Recover Nairobi failed payments');
    }

    public function test_sales_user_can_only_update_visible_priority_status(): void
    {
        [$platform] = $this->market();
        $admin = $this->user(['role' => 'admin']);
        $sales = $this->user(['role' => 'sales', 'assigned_market_ids' => [$platform->id]]);
        $priority = WeeklyPriority::query()->create([
            'title' => 'Close weekly renewal list',
            'status' => 'pending',
            'priority_level' => 'normal',
            'audience' => 'sales',
            'platform_id' => $platform->id,
            'created_by' => $admin->id,
            'week_start' => Carbon::now()->startOfWeek()->toDateString(),
            'week_end' => Carbon::now()->endOfWeek()->toDateString(),
            'completion_mode' => 'manual',
        ]);

        Sanctum::actingAs($sales);
        $this->patchJson('/api/crm/priorities/'.$priority->id, [
            'title' => 'Rename by sales',
        ])->assertForbidden();

        $this->patchJson('/api/crm/priorities/'.$priority->id, [
            'status' => 'completed',
        ])->assertOk()
            ->assertJsonPath('priority.status', 'completed');

        $this->assertNotNull($priority->fresh()->completed_at);
    }

    public function test_metric_priority_auto_completes_when_revenue_target_is_met(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00', 'Africa/Nairobi'));
        [$platform, $product] = $this->market(['currency_code' => 'USD']);
        $admin = $this->user(['role' => 'admin', 'is_ceo' => true]);

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'amount' => 150,
            'currency' => 'USD',
            'status' => 'completed',
            'purpose' => 'subscription',
            'provider_environment' => null,
            'record_classification' => Payment::RECORD_CLASSIFICATION_LIVE,
            'reconciliation_state' => 'open',
            'resolution_code' => null,
            'completed_at' => Carbon::parse('2026-08-19 09:00:00', 'Africa/Nairobi')->utc(),
            'created_at' => Carbon::parse('2026-08-19 09:00:00', 'Africa/Nairobi')->utc(),
        ]);

        Sanctum::actingAs($admin);
        $this->postJson('/api/crm/priorities', [
            'title' => 'Push Kenya weekly revenue past USD 100',
            'audience' => 'sales',
            'platform_id' => $platform->id,
            'completion_mode' => 'metric',
            'metric_key' => 'revenue',
            'target_operator' => 'gte',
            'target_value' => 100,
            'target_currency' => 'USD',
        ])->assertCreated()
            ->assertJsonPath('priority.status', 'completed')
            ->assertJsonPath('priority.current_value', 150);

        Carbon::setTestNow();
    }

    public function test_sales_user_cannot_create_priority(): void
    {
        Sanctum::actingAs($this->user(['role' => 'sales']));

        $this->postJson('/api/crm/priorities', [
            'title' => 'Unauthorized priority',
        ])->assertForbidden();
    }

    /** @return array{0:Platform,1:Product} */
    private function market(array $platformOverrides = []): array
    {
        $platform = Platform::factory()->create(array_merge(['currency_code' => 'USD'], $platformOverrides));
        $product = Product::factory()->create(['platform_id' => $platform->id, 'currency' => $platform->currency_code ?? 'USD']);

        return [$platform, $product];
    }

    private function user(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'Priority User',
            'email' => Str::uuid().'@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
            'is_ceo' => false,
            'assigned_market_ids' => [],
        ], $overrides));
    }
}
