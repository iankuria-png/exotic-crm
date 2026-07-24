<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\CrmAuditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_password_login_is_recorded_to_the_audit_log(): void
    {
        $user = User::factory()->create([
            'email' => 'agent@exotic-online.com',
            'role' => 'sales',
            'status' => 'active',
        ]);

        $this->postJson('/api/crm/login', [
            'email' => 'agent@exotic-online.com',
            'password' => 'password',
        ])->assertOk();

        $this->assertDatabaseHas('audit_log', [
            'actor_id' => $user->id,
            'action' => CrmAuditAction::AUTH_LOGIN,
            'entity_type' => 'user',
            'entity_id' => $user->id,
        ]);
    }

    public function test_failed_login_on_a_known_account_is_recorded(): void
    {
        $user = User::factory()->create([
            'email' => 'agent@exotic-online.com',
            'role' => 'sales',
            'status' => 'active',
        ]);

        $this->postJson('/api/crm/login', [
            'email' => 'agent@exotic-online.com',
            'password' => 'wrong-password',
        ])->assertStatus(401);

        $this->assertDatabaseHas('audit_log', [
            'actor_id' => $user->id,
            'action' => CrmAuditAction::AUTH_LOGIN_FAILED,
        ]);
    }

    public function test_login_attempt_on_an_inactive_account_is_recorded(): void
    {
        $user = User::factory()->create([
            'email' => 'suspended@exotic-online.com',
            'role' => 'field_sales',
            'status' => 'inactive',
        ]);

        $this->postJson('/api/crm/login', [
            'email' => 'suspended@exotic-online.com',
            'password' => 'password',
        ])->assertStatus(403);

        $this->assertDatabaseHas('audit_log', [
            'actor_id' => $user->id,
            'action' => CrmAuditAction::AUTH_LOGIN_FAILED,
            'reason' => 'Account is inactive.',
        ]);
    }

    public function test_admin_can_read_a_users_full_audit_trail(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $target = User::factory()->create(['role' => 'field_sales', 'status' => 'active']);

        AuditLog::create([
            'platform_id' => null,
            'actor_id' => $target->id,
            'action' => CrmAuditAction::AUTH_LOGIN,
            'entity_type' => 'user',
            'entity_id' => $target->id,
            'reason' => 'Signed in with password.',
            'ip_address' => '41.90.1.1',
            'created_at' => now(),
        ]);
        AuditLog::create([
            'platform_id' => null,
            'actor_id' => $target->id,
            'action' => CrmAuditAction::DEAL_FREE_TRIAL,
            'entity_type' => 'deal',
            'entity_id' => 55,
            'reason' => 'Free trial granted.',
            'ip_address' => '41.90.1.1',
            'created_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/crm/settings/roles/{$target->id}/audit")
            ->assertOk()
            ->assertJsonPath('user.id', $target->id)
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.distinct_ips', 1);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_audit_trail_can_be_filtered_by_action(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $target = User::factory()->create(['role' => 'sales', 'status' => 'active']);

        AuditLog::create([
            'platform_id' => null,
            'actor_id' => $target->id,
            'action' => CrmAuditAction::AUTH_LOGIN,
            'entity_type' => 'user',
            'entity_id' => $target->id,
            'created_at' => now(),
        ]);
        AuditLog::create([
            'platform_id' => null,
            'actor_id' => $target->id,
            'action' => CrmAuditAction::DEAL_FREE_TRIAL,
            'entity_type' => 'deal',
            'entity_id' => 7,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/crm/settings/roles/{$target->id}/audit?action=" . CrmAuditAction::AUTH_LOGIN)
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(CrmAuditAction::AUTH_LOGIN, $response->json('data.0.action'));
    }

    public function test_non_admin_cannot_read_a_users_audit_trail(): void
    {
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $target = User::factory()->create(['role' => 'field_sales', 'status' => 'active']);

        Sanctum::actingAs($sales);

        $this->getJson("/api/crm/settings/roles/{$target->id}/audit")
            ->assertStatus(403);
    }
}
