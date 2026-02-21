<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\IntegrationSetting;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\RenewalCampaign;
use App\Models\Template;
use App\Models\TimelineEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrmStreamFourAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_user_gets_403_for_out_of_scope_platform_filter(): void
    {
        $platformA = $this->createPlatform('Kenya');
        $platformB = $this->createPlatform('Uganda');
        $salesUser = $this->createUser('sales', [$platformA->id]);
        $this->createDeal($platformA);
        $this->createDeal($platformB);

        Sanctum::actingAs($salesUser);

        $response = $this->getJson('/api/crm/deals?platform_id=' . $platformB->id);

        $response->assertStatus(403);
    }

    public function test_batch_match_is_scoped_to_assigned_markets(): void
    {
        $platformA = $this->createPlatform('Kenya');
        $platformB = $this->createPlatform('Uganda');
        $salesUser = $this->createUser('sales', [$platformA->id]);

        $clientA = $this->createClient($platformA, ['phone_normalized' => '254711000001']);
        $clientB = $this->createClient($platformB, ['phone_normalized' => '254711000002']);

        $paymentA = Payment::query()->create([
            'platform_id' => $platformA->id,
            'phone' => '0711000001',
            'amount' => 1000,
            'status' => 'completed',
        ]);

        $paymentB = Payment::query()->create([
            'platform_id' => $platformB->id,
            'phone' => '0711000002',
            'amount' => 1000,
            'status' => 'completed',
        ]);

        Sanctum::actingAs($salesUser);

        $response = $this->postJson('/api/crm/payments/batch-match', [
            'reason' => 'Batch match during queue review',
        ]);

        $response->assertOk();
        $this->assertSame($clientA->id, $paymentA->fresh()->client_id);
        $this->assertNull($paymentB->fresh()->client_id);
        $this->assertNotNull($clientB->id); // ensure second market fixture exists
    }

    public function test_confirm_match_requires_reason(): void
    {
        $platform = $this->createPlatform('Kenya');
        $user = $this->createUser('sales', [$platform->id]);
        $client = $this->createClient($platform);

        $payment = Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '0711000003',
            'amount' => 1400,
            'status' => 'completed',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/payments/{$payment->id}/confirm-match", [
            'client_id' => $client->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }

    public function test_extend_deal_requires_reason(): void
    {
        $platform = $this->createPlatform('Kenya');
        $user = $this->createUser('sales', [$platform->id]);
        $deal = $this->createDeal($platform, [
            'status' => 'active',
            'expires_at' => now()->addDays(3),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/deals/{$deal->id}/extend", [
            'additional_days' => 7,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }

    public function test_sales_user_cannot_create_templates(): void
    {
        $platform = $this->createPlatform('Kenya');
        $user = $this->createUser('sales', [$platform->id]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/settings/templates', [
            'platform_id' => $platform->id,
            'title' => 'Reminder',
            'category' => 'renewal',
            'channel' => 'sms',
            'body' => 'Hello {{client_name}}',
            'status' => 'active',
        ]);

        $response->assertStatus(403);
    }

    public function test_sub_admin_cannot_view_roles_matrix(): void
    {
        $platform = $this->createPlatform('Kenya');
        $user = $this->createUser('sub_admin', [$platform->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/settings/roles');

        $response->assertStatus(403);
    }

    public function test_renewal_run_is_scoped_to_accessible_markets(): void
    {
        $platformA = $this->createPlatform('Kenya');
        $platformB = $this->createPlatform('Uganda');
        $subAdmin = $this->createUser('sub_admin', [$platformA->id]);
        $template = $this->createTemplate();

        RenewalCampaign::query()->create([
            'trigger_days' => -7,
            'channel' => 'sms',
            'template_id' => $template->id,
            'enabled' => true,
        ]);

        $this->createDeal($platformA, [
            'status' => 'active',
            'expires_at' => now()->addDays(7),
        ]);

        $this->createDeal($platformB, [
            'status' => 'active',
            'expires_at' => now()->addDays(7),
        ]);

        Sanctum::actingAs($subAdmin);

        $response = $this->postJson('/api/crm/renewals/run');

        $response->assertOk();
        $response->assertJsonPath('totals.targeted', 1);
    }

    public function test_renewal_overview_returns_reminder_counts_per_subscription(): void
    {
        $platform = $this->createPlatform('Kenya');
        $salesUser = $this->createUser('sales', [$platform->id]);
        $deal = $this->createDeal($platform, [
            'status' => 'active',
            'expires_at' => now()->addDays(6),
        ]);

        TimelineEvent::query()->create([
            'platform_id' => $platform->id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'renewal_sms_sent',
            'actor_id' => $salesUser->id,
            'content' => ['campaign_id' => 1],
            'created_at' => now()->subDays(3),
        ]);

        TimelineEvent::query()->create([
            'platform_id' => $platform->id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'renewal_sms_sent',
            'actor_id' => $salesUser->id,
            'content' => ['campaign_id' => 2],
            'created_at' => now()->subDay(),
        ]);

        TimelineEvent::query()->create([
            'platform_id' => $platform->id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'renewal_sms_failed',
            'actor_id' => $salesUser->id,
            'content' => ['campaign_id' => 3],
            'created_at' => now()->subHours(10),
        ]);

        Sanctum::actingAs($salesUser);

        $response = $this->getJson('/api/crm/renewals?platform_id=' . $platform->id);

        $response->assertOk();
        $response->assertJsonPath('targets.data.0.id', $deal->id);
        $response->assertJsonPath('targets.data.0.reminders_sent_count', 2);
        $response->assertJsonPath('targets.data.0.reminders_failed_count', 1);
        $this->assertNotNull($response->json('targets.data.0.last_renewal_reminder_at'));
    }

    public function test_sales_user_can_pause_and_resume_renewal_reminders_in_scope(): void
    {
        $platform = $this->createPlatform('Kenya');
        $salesUser = $this->createUser('sales', [$platform->id]);
        $deal = $this->createDeal($platform, [
            'status' => 'active',
            'expires_at' => now()->addDays(5),
        ]);

        Sanctum::actingAs($salesUser);

        $pauseResponse = $this->postJson('/api/crm/renewals/pause', [
            'deal_id' => $deal->id,
            'pause_until' => now()->addDays(2)->toDateString(),
            'reason' => 'Client requested temporary pause',
        ]);

        $pauseResponse->assertOk()
            ->assertJsonPath('status', 'paused');
        $this->assertTrue((bool) $deal->fresh()->renewal_reminders_paused);

        $overviewPaused = $this->getJson('/api/crm/renewals?bucket=paused');
        $overviewPaused->assertOk()
            ->assertJsonPath('targets.data.0.id', $deal->id)
            ->assertJsonPath('targets.data.0.reminders_paused', true);

        $resumeResponse = $this->postJson('/api/crm/renewals/resume', [
            'deal_id' => $deal->id,
            'reason' => 'Client requested reminders back on',
        ]);

        $resumeResponse->assertOk()
            ->assertJsonPath('status', 'active');

        $this->assertFalse((bool) $deal->fresh()->renewal_reminders_paused);
    }

    public function test_paused_renewal_target_is_excluded_from_campaign_run(): void
    {
        $platform = $this->createPlatform('Kenya');
        $salesUser = $this->createUser('sales', [$platform->id]);
        $template = $this->createTemplate();

        RenewalCampaign::query()->create([
            'trigger_days' => -7,
            'channel' => 'sms',
            'template_id' => $template->id,
            'enabled' => true,
        ]);

        $this->createDeal($platform, [
            'status' => 'active',
            'expires_at' => now()->addDays(7),
            'renewal_reminders_paused' => false,
        ]);

        $this->createDeal($platform, [
            'status' => 'active',
            'expires_at' => now()->addDays(7),
            'renewal_reminders_paused' => true,
            'renewal_paused_until' => now()->addDays(3),
            'renewal_pause_reason' => 'Temporary stop',
        ]);

        Sanctum::actingAs($salesUser);

        $response = $this->postJson('/api/crm/renewals/run');

        $response->assertOk()
            ->assertJsonPath('totals.targeted', 1);
    }

    public function test_sales_user_can_create_manual_client_in_scope(): void
    {
        $platform = $this->createPlatform('Kenya');
        $salesUser = $this->createUser('sales', [$platform->id]);

        Sanctum::actingAs($salesUser);

        $response = $this->postJson('/api/crm/clients', [
            'platform_id' => $platform->id,
            'name' => 'Manual Client',
            'phone_normalized' => '0712555000',
            'email' => 'manual.client@example.test',
            'city' => 'Nairobi',
            'profile_status' => 'private',
            'reason' => 'Manual intake',
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Manual Client')
            ->assertJsonPath('platform_id', $platform->id);

        $this->assertDatabaseHas('clients', [
            'name' => 'Manual Client',
            'platform_id' => $platform->id,
            'profile_status' => 'private',
        ]);
    }

    public function test_client_search_supports_crm_and_wordpress_ids_in_scope(): void
    {
        $platformA = $this->createPlatform('Kenya');
        $platformB = $this->createPlatform('Uganda');
        $salesUser = $this->createUser('sales', [$platformA->id]);

        $clientA = $this->createClient($platformA, [
            'wp_post_id' => 456700,
            'wp_user_id' => 76540,
            'name' => 'Traceable Client A',
        ]);

        $this->createClient($platformB, [
            'wp_post_id' => 456701,
            'wp_user_id' => 76541,
            'name' => 'Out of scope Client',
        ]);

        Sanctum::actingAs($salesUser);

        $byCrmId = $this->getJson('/api/crm/clients?search=' . $clientA->id);
        $byWpPostId = $this->getJson('/api/crm/clients?search=456700');
        $byWpUserId = $this->getJson('/api/crm/clients?search=76540');

        $byCrmId->assertOk()
            ->assertJsonPath('data.0.id', $clientA->id)
            ->assertJsonCount(1, 'data');
        $byWpPostId->assertOk()
            ->assertJsonPath('data.0.id', $clientA->id)
            ->assertJsonCount(1, 'data');
        $byWpUserId->assertOk()
            ->assertJsonPath('data.0.id', $clientA->id)
            ->assertJsonCount(1, 'data');
    }

    public function test_sales_user_can_create_and_reassign_lead_in_scope(): void
    {
        $platform = $this->createPlatform('Kenya');
        $salesUser = $this->createUser('sales', [$platform->id]);
        $otherOwner = $this->createUser('sales', [$platform->id]);

        Sanctum::actingAs($salesUser);

        $createResponse = $this->postJson('/api/crm/leads', [
            'platform_id' => $platform->id,
            'name' => 'Outbound Lead',
            'phone_normalized' => '0712444000',
            'source' => 'outbound',
            'assigned_to' => $otherOwner->id,
            'reason' => 'Manual lead intake',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('assigned_to', $otherOwner->id);

        $leadId = (int) $createResponse->json('id');

        $assignResponse = $this->patchJson("/api/crm/leads/{$leadId}/assign", [
            'assigned_to' => $salesUser->id,
            'reason' => 'Reassign to primary owner',
        ]);

        $assignResponse->assertOk()
            ->assertJsonPath('assigned_to', $salesUser->id);
    }

    public function test_sales_user_can_create_scrape_entry_lead_in_scope(): void
    {
        $platform = $this->createPlatform('Kenya');
        $salesUser = $this->createUser('sales', [$platform->id]);
        $owner = $this->createUser('sales', [$platform->id]);

        Sanctum::actingAs($salesUser);

        $response = $this->postJson('/api/crm/leads/scrape-entry', [
            'platform_id' => $platform->id,
            'source_url' => 'https://example.com/profile/traceable-listing',
            'name' => 'Scraped Listing',
            'phone_normalized' => '0712555111',
            'assigned_to' => $owner->id,
            'reason' => 'Scrape intake QA',
        ]);

        $response->assertCreated()
            ->assertJsonPath('lead.platform_id', $platform->id)
            ->assertJsonPath('lead.name', 'Scraped Listing')
            ->assertJsonPath('lead.assigned_to', $owner->id)
            ->assertJsonPath('lead.source', 'import');

        $leadId = (int) $response->json('lead.id');

        $this->assertDatabaseHas('timeline_events', [
            'entity_type' => 'lead',
            'entity_id' => $leadId,
            'event_type' => 'lead_scrape_intake',
        ]);

        $this->assertDatabaseHas('audit_log', [
            'entity_type' => 'lead',
            'entity_id' => $leadId,
            'action' => 'lead_scrape_intake',
        ]);
    }

    public function test_sales_user_can_archive_lead_and_include_archived_filter_returns_it(): void
    {
        $platform = $this->createPlatform('Kenya');
        $salesUser = $this->createUser('sales', [$platform->id]);
        $lead = $this->createLead($platform, [
            'name' => 'Archive Target',
            'status' => 'new',
        ]);

        Sanctum::actingAs($salesUser);

        $archiveResponse = $this->patchJson("/api/crm/leads/{$lead->id}/archive", [
            'reason' => 'Duplicate profile follow-up merged',
        ]);

        $archiveResponse->assertOk()
            ->assertJsonPath('lead.id', $lead->id);

        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
        ]);
        $this->assertNotNull($lead->fresh()->archived_at);

        $defaultList = $this->getJson('/api/crm/leads');
        $defaultList->assertOk()
            ->assertJsonPath('total', 0);

        $archivedList = $this->getJson('/api/crm/leads?include_archived=1');
        $archivedList->assertOk()
            ->assertJsonPath('data.0.id', $lead->id);
    }

    public function test_delete_lead_requires_reason_and_removes_record(): void
    {
        $platform = $this->createPlatform('Kenya');
        $salesUser = $this->createUser('sales', [$platform->id]);
        $lead = $this->createLead($platform, [
            'name' => 'Delete Target',
        ]);

        Sanctum::actingAs($salesUser);

        $withoutReason = $this->deleteJson("/api/crm/leads/{$lead->id}");
        $withoutReason->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $withReason = $this->deleteJson("/api/crm/leads/{$lead->id}", [
            'reason' => 'Invalid contact details',
        ]);

        $withReason->assertOk();
        $this->assertDatabaseMissing('leads', [
            'id' => $lead->id,
        ]);
    }

    public function test_admin_can_update_role_and_market_assignments(): void
    {
        $platformA = $this->createPlatform('Kenya');
        $platformB = $this->createPlatform('Uganda');
        $admin = $this->createUser('admin', []);
        $targetUser = $this->createUser('sales', [$platformA->id]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/crm/settings/roles/{$targetUser->id}", [
            'role' => 'sub_admin',
            'status' => 'active',
            'assigned_market_ids' => [$platformA->id, $platformB->id],
            'reason' => 'Promoted to manager',
        ]);

        $response->assertOk()
            ->assertJsonPath('role', 'sub_admin')
            ->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('users', [
            'id' => $targetUser->id,
            'role' => 'sub_admin',
        ]);
        $this->assertDatabaseHas('user_platforms', [
            'user_id' => $targetUser->id,
            'platform_id' => $platformA->id,
        ]);
        $this->assertDatabaseHas('user_platforms', [
            'user_id' => $targetUser->id,
            'platform_id' => $platformB->id,
        ]);
    }

    public function test_admin_can_create_user_with_role_and_market_assignments(): void
    {
        $platformA = $this->createPlatform('Kenya');
        $platformB = $this->createPlatform('Uganda');
        $admin = $this->createUser('admin', []);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/crm/settings/roles/users', [
            'name' => 'New Sales Agent',
            'email' => 'new.sales.agent@example.test',
            'password' => 'StrongPass123!',
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [$platformA->id, $platformB->id],
            'reason' => 'New regional hire',
        ]);

        $response->assertCreated()
            ->assertJsonPath('role', 'sales')
            ->assertJsonPath('status', 'active');

        $userId = (int) $response->json('id');

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => 'new.sales.agent@example.test',
            'role' => 'sales',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('user_platforms', [
            'user_id' => $userId,
            'platform_id' => $platformA->id,
        ]);
        $this->assertDatabaseHas('user_platforms', [
            'user_id' => $userId,
            'platform_id' => $platformB->id,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'entity_type' => 'user',
            'entity_id' => $userId,
            'action' => 'user_create',
        ]);
    }

    public function test_sales_user_can_upload_leads_csv_in_scope(): void
    {
        $platform = $this->createPlatform('Kenya');
        $salesUser = $this->createUser('sales', [$platform->id]);

        Sanctum::actingAs($salesUser);

        $csv = <<<CSV
name,phone,email,source,status
Lead A,0712000001,lead-a@example.test,outbound,new
Lead B,0712000002,lead-b@example.test,referral,contacted
CSV;

        $response = $this->post('/api/crm/leads/upload-csv', [
            'platform_id' => $platform->id,
            'has_header' => true,
            'reason' => 'CSV import test',
            'file' => UploadedFile::fake()->createWithContent('leads.csv', $csv),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk()
            ->assertJsonPath('totals.created', 2)
            ->assertJsonPath('totals.failed', 0);

        $this->assertDatabaseHas('leads', [
            'platform_id' => $platform->id,
            'name' => 'Lead A',
        ]);
        $this->assertDatabaseHas('leads', [
            'platform_id' => $platform->id,
            'name' => 'Lead B',
        ]);
    }

    public function test_sales_user_can_upload_clients_csv_in_scope(): void
    {
        $platform = $this->createPlatform('Kenya');
        $salesUser = $this->createUser('sales', [$platform->id]);

        Sanctum::actingAs($salesUser);

        $csv = <<<CSV
name,phone,email,city,status
Client A,0713000001,client-a@example.test,Nairobi,private
Client B,0713000002,client-b@example.test,Mombasa,publish
CSV;

        $response = $this->post('/api/crm/clients/upload-csv', [
            'platform_id' => $platform->id,
            'has_header' => true,
            'reason' => 'CSV import test',
            'file' => UploadedFile::fake()->createWithContent('clients.csv', $csv),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk()
            ->assertJsonPath('totals.created', 2)
            ->assertJsonPath('totals.failed', 0);

        $this->assertDatabaseHas('clients', [
            'platform_id' => $platform->id,
            'name' => 'Client A',
        ]);
        $this->assertDatabaseHas('clients', [
            'platform_id' => $platform->id,
            'name' => 'Client B',
        ]);
    }

    public function test_dashboard_summary_can_be_filtered_to_single_market(): void
    {
        $platformA = $this->createPlatform('Kenya');
        $platformB = $this->createPlatform('Uganda');
        $salesUser = $this->createUser('sales', [$platformA->id, $platformB->id]);

        $this->createDeal($platformA, [
            'status' => 'active',
            'expires_at' => now()->addDays(5),
        ]);
        $this->createDeal($platformB, [
            'status' => 'active',
            'expires_at' => now()->addDays(5),
        ]);

        Payment::query()->create([
            'platform_id' => $platformA->id,
            'phone' => '0711000999',
            'amount' => 1200,
            'status' => 'completed',
            'client_id' => null,
        ]);

        Payment::query()->create([
            'platform_id' => $platformB->id,
            'phone' => '0711000888',
            'amount' => 1600,
            'status' => 'completed',
            'client_id' => null,
        ]);

        Sanctum::actingAs($salesUser);

        $allResponse = $this->getJson('/api/crm/dashboard');
        $filteredResponse = $this->getJson('/api/crm/dashboard?platform_id=' . $platformA->id);

        $allResponse->assertOk();
        $filteredResponse->assertOk()
            ->assertJsonPath('filters.platform_id', $platformA->id);

        $this->assertGreaterThanOrEqual(
            $filteredResponse->json('kpis.unmatched_payments'),
            $allResponse->json('kpis.unmatched_payments')
        );
    }

    public function test_reports_summary_returns_funnel_stages_and_owner_totals_for_selected_range(): void
    {
        $platform = $this->createPlatform('Kenya');
        $salesUser = $this->createUser('sales', [$platform->id]);
        $ownerA = $this->createUser('sales', [$platform->id]);
        $ownerB = $this->createUser('sales', [$platform->id]);

        $this->createLead($platform, ['status' => 'new']);
        $this->createLead($platform, ['status' => 'contacted']);
        $this->createLead($platform, ['status' => 'qualified']);
        $this->createLead($platform, ['status' => 'converted']);
        $this->createLead($platform, ['status' => 'lost']);
        $this->createLead($platform, ['status' => 'new', 'archived_at' => now()]);

        $this->createDeal($platform, [
            'assigned_to' => $ownerA->id,
            'status' => 'active',
            'amount' => 4000,
        ]);
        $this->createDeal($platform, [
            'assigned_to' => $ownerA->id,
            'status' => 'pending',
            'amount' => 1500,
        ]);
        $this->createDeal($platform, [
            'assigned_to' => $ownerB->id,
            'status' => 'expired',
            'amount' => 2200,
        ]);
        $this->createDeal($platform, [
            'assigned_to' => $ownerB->id,
            'status' => 'cancelled',
            'amount' => 3000,
        ]);

        Sanctum::actingAs($salesUser);

        $response = $this->getJson('/api/crm/reports/summary?from=' . now()->subDays(7)->toDateString() . '&to=' . now()->toDateString());

        $response->assertOk()
            ->assertJsonPath('lead_funnel.new', 1)
            ->assertJsonPath('lead_funnel.contacted', 1)
            ->assertJsonPath('lead_funnel.qualified', 1)
            ->assertJsonPath('lead_funnel.converted', 1)
            ->assertJsonPath('lead_funnel.lost', 1)
            ->assertJsonPath('lead_funnel_stages.0.key', 'new')
            ->assertJsonPath('owner_performance_top_owner.owner', $ownerA->name)
            ->assertJsonPath('owner_performance_totals.subscriptions', 3)
            ->assertJsonPath('owner_performance_totals.active_subscriptions', 1);

        $this->assertSame(5, $response->json('lead_funnel_totals.total'));
        $this->assertSame(3, $response->json('lead_funnel_totals.workable'));
        $this->assertSame(1, $response->json('lead_funnel_totals.converted'));
        $this->assertSame(1, $response->json('lead_funnel_totals.lost'));
        $this->assertSame(7700.0, (float) $response->json('owner_performance_totals.revenue'));
    }

    public function test_webhook_logs_return_humanized_incident_fields_and_scope(): void
    {
        $platformA = $this->createPlatform('Kenya');
        $platformB = $this->createPlatform('Uganda');
        $salesUser = $this->createUser('sales', [$platformA->id]);

        AuditLog::query()->create([
            'platform_id' => $platformA->id,
            'actor_id' => $salesUser->id,
            'action' => 'renewal_sms_failed',
            'entity_type' => 'deal',
            'entity_id' => 101,
            'before_state' => null,
            'after_state' => [
                'status' => 'failed',
            ],
            'reason' => 'Provider timeout during renewal run',
            'ip_address' => '127.0.0.1',
            'created_at' => now()->subMinute(),
        ]);

        AuditLog::query()->create([
            'platform_id' => $platformB->id,
            'actor_id' => $salesUser->id,
            'action' => 'renewal_sms_failed',
            'entity_type' => 'deal',
            'entity_id' => 202,
            'before_state' => null,
            'after_state' => [
                'status' => 'failed',
            ],
            'reason' => 'Out-of-scope market event',
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);

        Sanctum::actingAs($salesUser);

        $response = $this->getJson('/api/crm/settings/webhook-logs');

        $response->assertOk()
            ->assertJsonPath('data.0.platform_id', $platformA->id)
            ->assertJsonPath('data.0.action', 'renewal_sms_failed')
            ->assertJsonPath('data.0.incident.title', 'Renewal reminder failed')
            ->assertJsonPath('data.0.incident.category', 'renewals')
            ->assertJsonPath('data.0.incident.severity', 'high');

        $this->assertSame('Check SMS provider health and resend from renewals workspace.', $response->json('data.0.incident.suggested_action'));
        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_can_update_sms_provider_configuration(): void
    {
        $platform = $this->createPlatform('Kenya');
        $admin = $this->createUser('admin', [$platform->id]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson('/api/crm/settings/integrations/sms-provider', [
            'enabled' => true,
            'active_provider' => 'africastalking',
            'fallback_provider' => 'legacy_gateway',
            'default_prefix' => '254',
            'legacy_gateway' => [
                'gateway_url' => 'https://legacy-sms.test/send',
                'org_code' => '76',
            ],
            'africastalking' => [
                'endpoint' => 'https://api.africastalking.com/version1/messaging',
                'username' => 'sandbox',
                'api_key' => 'secret-key-123',
                'sender_id' => 'EXOTIC',
            ],
            'reason' => 'Switch provider for redundancy test',
        ]);

        $response->assertOk()
            ->assertJsonPath('sms_provider.enabled', true)
            ->assertJsonPath('sms_provider.active_provider', 'africastalking')
            ->assertJsonPath('sms_provider.fallback_provider', 'legacy_gateway')
            ->assertJsonPath('sms_provider.africastalking.username', 'sandbox')
            ->assertJsonPath('sms_provider.africastalking.api_key_configured', true);

        $setting = IntegrationSetting::query()->where('key', 'sms_provider_config')->first();
        $this->assertNotNull($setting);
        $this->assertSame('africastalking', $setting->value['active_provider'] ?? null);
        $this->assertSame('legacy_gateway', $setting->value['fallback_provider'] ?? null);
        $this->assertSame('sandbox', $setting->value['africastalking']['username'] ?? null);
        $this->assertSame('secret-key-123', $setting->value['africastalking']['api_key'] ?? null);

        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'entity_type' => 'integration_setting',
            'entity_id' => 1,
            'action' => 'integration_platform_update',
        ]);
    }

    public function test_admin_can_test_sms_provider_dispatch(): void
    {
        $platform = $this->createPlatform('Kenya');
        $admin = $this->createUser('admin', [$platform->id]);

        IntegrationSetting::query()->create([
            'key' => 'sms_provider_config',
            'value' => [
                'enabled' => true,
                'active_provider' => 'legacy_gateway',
                'fallback_provider' => 'none',
                'default_prefix' => '254',
                'legacy_gateway' => [
                    'gateway_url' => 'https://legacy-sms.test/send',
                    'org_code' => '76',
                ],
                'africastalking' => [
                    'endpoint' => 'https://api.africastalking.com/version1/messaging',
                    'username' => '',
                    'api_key' => '',
                    'sender_id' => '',
                ],
            ],
            'updated_by' => $admin->id,
        ]);

        Http::fake([
            'https://legacy-sms.test/send' => Http::response('OK', 200),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/crm/settings/integrations/sms-provider/test', [
            'phone' => '0712000001',
            'message' => 'Testing provider dispatch',
            'reason' => 'Validate test endpoint',
        ]);

        $response->assertOk()
            ->assertJsonPath('result.success', true)
            ->assertJsonPath('result.provider', 'legacy_gateway')
            ->assertJsonPath('result.status', 'sent')
            ->assertJsonPath('result.phone', '254712000001');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://legacy-sms.test/send'
                && ($request['Phonenumber'] ?? null) === '254712000001'
                && ($request['OrgCode'] ?? null) === '76'
                && ($request['Message'] ?? null) === 'Testing provider dispatch';
        });

        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'entity_type' => 'integration_setting',
            'entity_id' => 1,
            'action' => 'integration_connection_test',
        ]);
    }

    public function test_admin_can_create_update_and_test_market_integration_profile(): void
    {
        $admin = $this->createUser('admin', []);

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/crm/settings/integrations/platforms', [
            'name' => 'Exotic Kenya',
            'domain' => 'kenya-market.test',
            'country' => 'Kenya',
            'is_active' => true,
            'wp_api_url' => 'https://kenya-api.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'sync-user',
            'wp_api_password' => 'sync-pass',
            'currency_code' => 'kes',
            'timezone' => 'Africa/Nairobi',
            'phone_prefix' => '254',
            'reason' => 'Onboarding new market',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('platform.platform_name', 'Exotic Kenya')
            ->assertJsonPath('platform.currency', 'KES')
            ->assertJsonPath('platform.wp_sync.status', 'connected');

        $platformId = (int) $createResponse->json('platform.platform_id');

        $updateResponse = $this->patchJson("/api/crm/settings/integrations/platforms/{$platformId}", [
            'country' => 'Kenya East',
            'is_active' => false,
            'reason' => 'Updated market profile details',
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('platform.country', 'Kenya East')
            ->assertJsonPath('platform.is_active', false);

        Http::fake([
            'https://kenya-api.test/wp-json/exotic-crm-sync/v1/stats*' => Http::response([
                'profiles_total' => 123,
                'active_profiles' => 77,
            ], 200),
        ]);

        $testResponse = $this->postJson("/api/crm/settings/integrations/platforms/{$platformId}/test-connection", [
            'reason' => 'Pre-sync health check',
        ]);

        $testResponse->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('platform.platform_id', $platformId)
            ->assertJsonPath('platform.sync.last_status', 'healthy');

        $this->assertDatabaseHas('platforms', [
            'id' => $platformId,
            'sync_last_status' => 'healthy',
            'country' => 'Kenya East',
            'is_active' => 0,
        ]);

        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platformId,
            'entity_type' => 'platform',
            'entity_id' => $platformId,
            'action' => 'integration_platform_create',
        ]);

        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platformId,
            'entity_type' => 'platform',
            'entity_id' => $platformId,
            'action' => 'integration_connection_test',
        ]);
    }

    public function test_sub_admin_can_run_leads_sync_for_owned_market_and_blocked_for_out_of_scope_market(): void
    {
        $platformA = $this->createPlatform('Kenya');
        $platformB = $this->createPlatform('Uganda');
        $subAdmin = $this->createUser('sub_admin', [$platformA->id]);

        Http::fake([
            $platformA->wp_api_url . '/clients*' => Http::response([
                'data' => [[
                    'wp_user_id' => 5501,
                    'wp_post_id' => 8801,
                    'name' => 'Imported Lead',
                    'phone' => '0712999999',
                    'email' => 'imported@example.test',
                    'needs_payment' => true,
                ]],
                'pages' => 1,
            ], 200),
        ]);

        Sanctum::actingAs($subAdmin);

        $syncResponse = $this->postJson("/api/crm/settings/integrations/platforms/{$platformA->id}/sync", [
            'scope' => 'leads',
            'dry_run' => true,
            'per_page' => 50,
            'reason' => 'Manual intake validation',
        ]);

        $syncResponse->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('result.scope', 'leads')
            ->assertJsonPath('result.dry_run', true)
            ->assertJsonPath('result.leads.eligible', 1);

        $this->assertSame(0, Lead::query()->count());
        $this->assertDatabaseHas('platforms', [
            'id' => $platformA->id,
            'sync_last_status' => 'success',
            'sync_last_scope' => 'leads',
        ]);

        $outOfScopeResponse = $this->postJson("/api/crm/settings/integrations/platforms/{$platformB->id}/sync", [
            'scope' => 'leads',
            'dry_run' => true,
        ]);

        $outOfScopeResponse->assertStatus(403);
    }

    private function createUser(string $role = 'sales', array $assignedMarketIds = []): User
    {
        return User::query()->create([
            'name' => ucfirst($role) . ' ' . Str::random(6),
            'email' => Str::random(8) . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => $assignedMarketIds,
        ]);
    }

    private function createPlatform(string $name): Platform
    {
        return Platform::query()->create([
            'name' => $name,
            'domain' => Str::slug($name) . '-' . Str::random(6) . '.test',
            'country' => $name,
            'is_active' => true,
            'wp_api_url' => 'https://example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function createProduct(): Product
    {
        return Product::query()->create([
            'name' => 'Premium ' . Str::random(4),
            'monthly_price' => 2500,
            'biweekly_price' => 1500,
            'weekly_price' => 900,
            'currency' => 'KES',
            'is_active' => true,
        ]);
    }

    private function createClient(Platform $platform, array $overrides = []): Client
    {
        return Client::query()->create(array_merge([
            'platform_id' => $platform->id,
            'wp_post_id' => random_int(1000, 999999),
            'name' => 'Client ' . Str::random(5),
            'phone_normalized' => '2547' . random_int(10000000, 99999999),
            'profile_status' => 'publish',
        ], $overrides));
    }

    private function createDeal(Platform $platform, array $overrides = []): Deal
    {
        $product = $this->createProduct();
        $client = $this->createClient($platform);
        $owner = $this->createUser('sales', [$platform->id]);

        return Deal::query()->create(array_merge([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'plan_type' => 'premium',
            'amount' => 2500,
            'currency' => 'KES',
            'duration' => 'monthly',
            'status' => 'pending',
            'assigned_to' => $owner->id,
        ], $overrides));
    }

    private function createLead(Platform $platform, array $overrides = []): Lead
    {
        return Lead::query()->create(array_merge([
            'platform_id' => $platform->id,
            'name' => 'Lead ' . Str::random(5),
            'phone_normalized' => '2547' . random_int(10000000, 99999999),
            'source' => 'outbound',
            'status' => 'new',
        ], $overrides));
    }

    private function createTemplate(): Template
    {
        return Template::query()->create([
            'title' => 'Renewal Reminder ' . Str::random(5),
            'category' => 'renewal',
            'channel' => 'sms',
            'body' => 'Hi {{client_name}}, your plan expires on {{expiry_date}}.',
            'status' => 'active',
            'variables' => ['client_name', 'expiry_date'],
        ]);
    }
}
