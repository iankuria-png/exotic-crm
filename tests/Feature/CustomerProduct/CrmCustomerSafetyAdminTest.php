<?php

namespace Tests\Feature\CustomerProduct;

use App\Models\Client;
use App\Models\CustomerSafetyReport;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Staff Safety slice on the Web Visitors workspace.
 *
 * These tests exist because the failure modes here are about who may see and
 * move a report, not about whether a table renders: a report's status is what
 * the reporting member reads back in their Safety Centre.
 */
class CrmCustomerSafetyAdminTest extends TestCase
{
    use RefreshDatabase;

    private function report(Platform $platform, array $attributes = []): CustomerSafetyReport
    {
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => $attributes['wp_post_id'] ?? 7001,
            'name' => $attributes['client_name'] ?? 'Reported Profile',
        ]);

        unset($attributes['client_name']);

        return CustomerSafetyReport::query()->create(array_merge([
            'reference' => 'SR-' . strtoupper(substr(md5((string) mt_rand()), 0, 8)),
            'customer_account_id' => null,
            'platform_id' => $platform->id,
            'wp_post_id' => $client->wp_post_id,
            'client_id' => $client->id,
            'category' => CustomerSafetyReport::CATEGORY_PHOTOS_NOT_REAL,
            'status' => CustomerSafetyReport::STATUS_RECEIVED,
            'source' => CustomerSafetyReport::SOURCE_MEMBER_PROFILE_REPORT,
            'submitted_at' => Carbon::now(),
        ], $attributes));
    }

    public function test_admin_reads_the_queue_with_status_counts(): void
    {
        $platform = Platform::factory()->create();
        $this->report($platform);
        $this->report($platform, ['wp_post_id' => 7002, 'status' => CustomerSafetyReport::STATUS_UNDER_REVIEW]);
        $this->report($platform, ['wp_post_id' => 7003, 'status' => CustomerSafetyReport::STATUS_CLOSED]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $this->getJson('/api/crm/visitors/safety-reports')
            ->assertOk()
            ->assertJsonPath('counts.received', 1)
            ->assertJsonPath('counts.under_review', 1)
            ->assertJsonPath('counts.closed', 1)
            ->assertJsonPath('counts.open', 2)
            ->assertJsonPath('permissions.can_manage', true)
            ->assertJsonCount(3, 'reports');
    }

    public function test_status_filter_does_not_change_the_queue_counts(): void
    {
        $platform = Platform::factory()->create();
        $this->report($platform);
        $this->report($platform, ['wp_post_id' => 7002, 'status' => CustomerSafetyReport::STATUS_CLOSED]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        // Counts describe the whole queue so the badges stay honest while a
        // staff member is looking at one slice of it.
        $this->getJson('/api/crm/visitors/safety-reports?status=closed')
            ->assertOk()
            ->assertJsonCount(1, 'reports')
            ->assertJsonPath('counts.received', 1)
            ->assertJsonPath('counts.closed', 1);
    }

    public function test_a_manager_can_move_a_report_and_the_move_is_attributed(): void
    {
        $platform = Platform::factory()->create();
        $report = $this->report($platform);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->putJson("/api/crm/visitors/safety-reports/{$report->id}", [
            'status' => CustomerSafetyReport::STATUS_UNDER_REVIEW,
            'review_note' => 'Checked the gallery against the KYC set.',
        ])
            ->assertOk()
            ->assertJsonPath('report.status', CustomerSafetyReport::STATUS_UNDER_REVIEW)
            ->assertJsonPath('report.is_open', true)
            ->assertJsonPath('report.reviewed_by', $admin->id);

        $this->assertDatabaseHas('customer_safety_reports', [
            'id' => $report->id,
            'status' => CustomerSafetyReport::STATUS_UNDER_REVIEW,
            'reviewed_by' => $admin->id,
            'review_note' => 'Checked the gallery against the KYC set.',
        ]);
    }

    public function test_a_sales_user_can_read_but_cannot_move_a_report(): void
    {
        $platform = Platform::factory()->create();
        $report = $this->report($platform);

        Sanctum::actingAs(User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [$platform->id],
        ]));

        $this->getJson('/api/crm/visitors/safety-reports')->assertOk();
        $this->putJson("/api/crm/visitors/safety-reports/{$report->id}", [
            'status' => CustomerSafetyReport::STATUS_CLOSED,
        ])->assertForbidden();

        $this->assertDatabaseHas('customer_safety_reports', [
            'id' => $report->id,
            'status' => CustomerSafetyReport::STATUS_RECEIVED,
        ]);
    }

    public function test_market_scope_hides_reports_from_other_markets(): void
    {
        $allowed = Platform::factory()->create();
        $other = Platform::factory()->create();
        $this->report($allowed, ['wp_post_id' => 7101]);
        $this->report($other, ['wp_post_id' => 7102]);

        Sanctum::actingAs(User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [$allowed->id],
        ]));

        $response = $this->getJson('/api/crm/visitors/safety-reports')->assertOk();

        $this->assertCount(1, $response->json('reports'));
        $this->assertSame(7101, $response->json('reports.0.wp_post_id'));
        $this->assertSame(1, $response->json('counts.received'));
    }

    public function test_an_unknown_status_is_refused_and_nothing_is_written(): void
    {
        $platform = Platform::factory()->create();
        $report = $this->report($platform);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $this->putJson("/api/crm/visitors/safety-reports/{$report->id}", ['status' => 'resolved_somehow'])
            ->assertStatus(422);

        $this->assertDatabaseHas('customer_safety_reports', [
            'id' => $report->id,
            'status' => CustomerSafetyReport::STATUS_RECEIVED,
            'reviewed_by' => null,
        ]);
    }

    public function test_the_member_free_text_is_never_in_the_staff_payload(): void
    {
        $platform = Platform::factory()->create();
        $this->report($platform);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $row = $this->getJson('/api/crm/visitors/safety-reports')->assertOk()->json('reports.0');

        // The member's written detail is emailed to staff and never stored, so
        // there must be no field here that could carry it.
        $this->assertArrayNotHasKey('details', $row);
        $this->assertArrayNotHasKey('message', $row);
        $this->assertArrayNotHasKey('member_email', $row);
        $this->assertArrayNotHasKey('customer_account_id', $row);
        $this->assertArrayHasKey('has_account_link', $row);
    }

    public function test_a_guest_cannot_reach_the_queue(): void
    {
        $platform = Platform::factory()->create();
        $report = $this->report($platform);

        $this->getJson('/api/crm/visitors/safety-reports')->assertUnauthorized();
        $this->putJson("/api/crm/visitors/safety-reports/{$report->id}", [
            'status' => CustomerSafetyReport::STATUS_CLOSED,
        ])->assertUnauthorized();
    }
}
