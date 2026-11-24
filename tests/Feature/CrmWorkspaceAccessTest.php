<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureCrmCapability;
use App\Models\User;
use App\Services\CrmAccessPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrmWorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function protectedRequests(): array
    {
        return [
            ['GET', '/api/crm/dashboard'], ['GET', '/api/crm/dashboard/country-revenue'],
            ['GET', '/api/crm/dashboard/ceo/summary'], ['GET', '/api/crm/payments'],
            ['POST', '/api/crm/payments/export'], ['GET', '/api/crm/payments/999999/diagnostics'],
            ['POST', '/api/crm/payments/999999/confirm-match'], ['GET', '/api/crm/reconciliations'],
            ['GET', '/api/crm/collections/receipts'], ['POST', '/api/crm/manual-payment-bundles/preview'],
            ['POST', '/api/crm/manual-payment-bundles/commit'], ['GET', '/api/crm/manual-payment-bundles/999999'],
            ['POST', '/api/crm/manual-payment-bundles/999999/approve'], ['POST', '/api/crm/manual-payment-bundles/999999/void'],
            ['GET', '/api/crm/payment-recovery-cases?export=1'], ['PATCH', '/api/crm/payment-recovery-cases/999999'],
            ['GET', '/api/payments'], ['GET', '/api/payments/999999'],
            ['POST', '/api/summary'], ['POST', '/api/dashboard-summary'],
            ['POST', '/api/register'], ['PUT', '/api/users/999999'],
            ['PATCH', '/api/crm/settings/roles/999999'], ['POST', '/api/crm/settings/roles/users'],
            ['POST', '/api/crm/settings/mcp/tokens'], ['PATCH', '/api/crm/settings/mcp/tokens/999999'],
            ['POST', '/api/crm/settings/roles/999999/impersonation-link'],
        ];
    }

    /** @dataProvider protectedRequests */
    public function test_restricted_admin_cannot_reach_protected_handlers_before_binding(string $method, string $uri): void
    {
        $user = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $user->forceFill(['access_profile' => CrmAccessPolicy::RESTRICTED])->save();
        Sanctum::actingAs($user);
        $before = User::query()->get()->toJson();

        $this->json($method, $uri, ['owner_id' => 999999, 'role' => 'admin', 'password' => 'must-not-change'])
            ->assertForbidden()->assertJsonPath('code', 'crm_access_denied');
        $this->assertSame($before, User::query()->get()->toJson());
    }

    public function test_old_or_forged_impersonation_cannot_escape_current_actor_policy(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $actor->forceFill(['access_profile' => CrmAccessPolicy::RESTRICTED])->save();
        $target = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        Sanctum::actingAs($actor);

        $this->withHeader('X-CRM-Impersonate-User', (string) $target->id)
            ->getJson('/api/crm/me')->assertForbidden();
    }

    public function test_profile_and_capabilities_are_returned_from_current_database_state(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $user->forceFill(['access_profile' => CrmAccessPolicy::RESTRICTED])->save();
        Sanctum::actingAs($user);

        $this->getJson('/api/crm/me')->assertOk()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('user.access_profile', CrmAccessPolicy::RESTRICTED)
            ->assertJsonPath('user.home_path', '/team')
            ->assertJsonPath('user.capabilities.payments_workspace', false)
            ->assertJsonPath('user.capabilities.admin_dashboard', false);
    }

    public function test_marketing_and_field_sales_cannot_load_the_general_dashboard(): void
    {
        foreach (['marketing', 'field_sales'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'status' => 'active']));
            $this->getJson('/api/crm/dashboard')->assertForbidden();
        }
    }

    public function test_existing_allowed_page_routes_are_not_classified_as_protected_workspaces(): void
    {
        $guard = app(EnsureCrmCapability::class);
        foreach (['api/crm/team/presence', 'api/crm/settings/integrations', 'api/crm/clients', 'api/crm/field/home', 'api/crm/dashboard/my-markets'] as $uri) {
            $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->uri() === $uri);
            $this->assertNotNull($route, $uri);
            $this->assertNull($guard->requirement($route), $uri);
        }
    }

    public function test_legacy_identity_and_financial_reads_require_bearer_authentication(): void
    {
        foreach ([['POST', '/api/register'], ['PUT', '/api/users/999999'], ['GET', '/api/users'],
            ['GET', '/api/payments'], ['GET', '/api/payments/999999'], ['POST', '/api/summary']] as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized();
        }
    }

    public function test_capability_matrix_keeps_existing_operators_and_unknown_profiles_fail_closed(): void
    {
        $policy = app(CrmAccessPolicy::class);
        foreach (['admin', 'sub_admin', 'sales', 'field_sales', 'marketing'] as $role) {
            $user = new User(['role' => $role, 'status' => 'active']);
            $this->assertSame($role !== 'marketing', $policy->allows($user, 'payments_workspace'));
            $this->assertSame(in_array($role, ['admin', 'sub_admin', 'sales'], true), $policy->allows($user, 'dashboard'));
            $user->access_profile = 'unknown_profile';
            $this->assertFalse($policy->allows($user, 'payments_workspace'));
            $this->assertFalse($policy->allows($user, 'dashboard'));
            $this->assertFalse($policy->allowsTool($user, 'exotic_revenue_summary'));
            $this->assertTrue($policy->allowsTool($user, 'exotic_system_vitals'));
            $user->status = 'inactive';
            $this->assertFalse($policy->allowsTool($user, 'exotic_system_vitals'));
        }
    }

    public function test_restricted_staff_keep_team_and_settings_reads(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $user->forceFill(['access_profile' => CrmAccessPolicy::RESTRICTED])->save();
        Sanctum::actingAs($user);
        $this->getJson('/api/crm/team/presence')->assertOk();
        $response = $this->getJson('/api/crm/settings/roles')->assertOk();
        $saved = collect($response->json('users'))->firstWhere('id', $user->id);
        $this->assertSame(CrmAccessPolicy::RESTRICTED, $saved['access_profile']);
    }

    public function test_unrestricted_admin_keeps_staff_management_access(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $this->postJson('/api/register', [])->assertUnprocessable();
        $this->putJson('/api/users/999999', [])->assertNotFound();
    }

    public function test_both_restored_field_agents_can_open_their_existing_home(): void
    {
        foreach ([21, 27] as $id) {
            $agent = User::factory()->create(['id' => $id, 'role' => 'field_sales', 'status' => 'active']);
            Sanctum::actingAs($agent);
            $this->getJson('/api/crm/field/home')->assertOk()->assertJsonPath('summary.clients_created', 0);
        }
    }

    public function test_retained_conversations_receive_only_their_existing_scoped_projection(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $user->forceFill(['access_profile' => CrmAccessPolicy::RESTRICTED])->save();
        Sanctum::actingAs($user);
        $this->mock(\App\Http\Controllers\CRM\DashboardController::class, function ($mock) use ($user) {
            $mock->shouldReceive('summary')->once()->withArgs(fn ($request) => $request->user()->id === $user->id)
                ->andReturn(response()->json([
                    'upcoming_follow_ups' => [['id' => 5]],
                    'kpis' => ['unmatched_payments' => 3, 'revenue' => 9999],
                    'payment_review_queue' => [['phone' => 'must-not-return']],
                ]));
        });

        $this->getJson('/api/crm/conversations/overview')->assertOk()->assertExactJson([
            'upcoming_follow_ups' => [['id' => 5]], 'kpis' => ['unmatched_payments' => 3],
        ]);
    }
}
