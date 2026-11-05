<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SubscriptionAudit\SubscriptionAuditService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class SubscriptionAuditControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_reporting_requires_an_active_administrator_and_a_purpose(): void
    {
        $this->getJson('/api/crm/reports/subscription-audit/summary')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'sales']));
        $this->getJson('/api/crm/reports/subscription-audit/summary?purpose=finance-review')->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/crm/reports/subscription-audit/summary')->assertUnprocessable()->assertJsonValidationErrors('purpose');
    }

    public function test_crm_read_adapter_preserves_partial_metadata_and_casts_query_filters(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $service = Mockery::mock(SubscriptionAuditService::class);
        $service->shouldReceive('report')->once()->withArgs(function ($kind, $arguments, $auth) use ($admin) {
            return $kind === 'events' && $arguments['purpose'] === 'finance review'
                && $arguments['page_size'] === 20 && $arguments['exceptions_only'] === true
                && $auth->user->is($admin) && $auth->allows('mcp:subscription-audit');
        })->andReturn([
            'run_ref' => 'f5cd845e-513f-43cd-b12e-3d6338a69084',
            'rows' => [],
            'total_matching' => 0,
            '_report_meta' => ['result_state' => 'partial', 'caveats' => ['History is incomplete.'], 'coverage' => [['state' => 'partial']]],
        ]);
        $this->app->instance(SubscriptionAuditService::class, $service);
        $this->getJson('/api/crm/reports/subscription-audit/events?'.http_build_query([
            'purpose' => 'finance review', 'page_size' => '20', 'exceptions_only' => '1',
        ]))->assertOk()->assertJsonPath('meta.result_state', 'partial')
            ->assertJsonPath('meta.caveats.0', 'History is incomplete.')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonMissingPath('data._report_meta');
    }

    public function test_crm_read_adapter_reports_no_snapshot_explicitly(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/crm/reports/subscription-audit/summary?purpose=finance-review')
            ->assertStatus(503)->assertJsonPath('error.reason', 'audit_unavailable');
    }

    public function test_crm_reporting_has_no_mutation_route_and_mcp_credentials_cannot_use_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $admin->createToken('mcp:audit-test', ['mcp:read', 'mcp:subscription-audit'])->plainTextToken;
        $this->withToken($token)->getJson('/api/crm/reports/subscription-audit/events?purpose=finance-review')->assertForbidden();
        $this->withoutToken();
        Sanctum::actingAs($admin);
        $this->postJson('/api/crm/reports/subscription-audit/events', ['purpose' => 'finance-review'])->assertStatus(405);
    }

    public function test_snapshot_refresh_is_backgrounded_and_spread_across_the_hour(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'crm:subscription-audit-build'));
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('13 * * * *', $event->expression);
        $this->assertTrue($event->runInBackground);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }
}
