<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\RenewalCampaign;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
