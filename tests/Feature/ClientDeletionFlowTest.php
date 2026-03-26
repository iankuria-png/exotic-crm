<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientNote;
use App\Models\ClientSyncExclusion;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Support\CrmAuditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientDeletionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_preview_reports_cascade_impact(): void
    {
        $platform = $this->createPlatform();
        $product = $this->createProduct($platform);
        $manager = $this->createManager($platform);
        $client = $this->createClient($platform, 9301);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'status' => 'active',
            'assigned_to' => $manager->id,
        ]);
        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'deal_id' => $deal->id,
            'client_id' => $client->id,
            'status' => 'completed',
        ]);
        ClientNote::create([
            'client_id' => $client->id,
            'author_id' => $manager->id,
            'note_type' => 'internal',
            'content' => 'Deletion preview note',
            'created_at' => now(),
        ]);
        Lead::create([
            'platform_id' => $platform->id,
            'name' => 'Lead linked to client',
            'phone_normalized' => '254711000111',
            'status' => 'qualified',
            'converted_client_id' => $client->id,
        ]);
        TimelineEvent::create([
            'platform_id' => $platform->id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'note_added',
            'actor_id' => $manager->id,
            'content' => ['preview' => true],
            'created_at' => now(),
        ]);

        Sanctum::actingAs($manager);

        $this->postJson("/api/crm/clients/{$client->id}/delete-preview")
            ->assertOk()
            ->assertJsonPath('deals_count', 1)
            ->assertJsonPath('payments_count', 1)
            ->assertJsonPath('notes_count', 1)
            ->assertJsonPath('leads_count', 1)
            ->assertJsonPath('timeline_events_count', 1)
            ->assertJsonPath('has_active_deal', true)
            ->assertJsonPath('wp_post_id', 9301);
    }

    public function test_destroy_deletes_client_inserts_exclusion_and_calls_wordpress_after_commit(): void
    {
        $platform = $this->createPlatform();
        $product = $this->createProduct($platform);
        $manager = $this->createManager($platform);
        $client = $this->createClient($platform, 9302);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'status' => 'awaiting_payment',
            'assigned_to' => $manager->id,
        ]);
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'deal_id' => $deal->id,
            'client_id' => $client->id,
            'status' => 'initiated',
        ]);
        $lead = Lead::create([
            'platform_id' => $platform->id,
            'name' => 'Lead linked to client',
            'phone_normalized' => '254711000222',
            'status' => 'qualified',
            'converted_client_id' => $client->id,
        ]);
        ClientNote::create([
            'client_id' => $client->id,
            'author_id' => $manager->id,
            'note_type' => 'internal',
            'content' => 'Delete me',
            'created_at' => now(),
        ]);

        Http::fake([
            'https://example.test/wp-json/exotic-crm-sync/v1/clients/9302/delete' => Http::response([
                'deleted' => true,
            ], 200),
        ]);

        Sanctum::actingAs($manager);

        $this->deleteJson("/api/crm/clients/{$client->id}", [
            'confirm' => $client->name,
            'reason' => 'Client requested erasure',
        ])->assertOk()
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('wp_deleted', true)
            ->assertJsonPath('impact.deals_count', 1);

        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
        $this->assertDatabaseMissing('deals', ['id' => $deal->id]);
        $this->assertDatabaseMissing('client_notes', ['client_id' => $client->id]);
        $this->assertDatabaseHas('client_sync_exclusions', [
            'platform_id' => $platform->id,
            'wp_post_id' => 9302,
            'deleted_by' => $manager->id,
        ]);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'client_id' => null,
            'deal_id' => null,
        ]);
        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'converted_client_id' => null,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'action' => CrmAuditAction::CLIENT_DELETE,
            'entity_type' => 'client',
            'entity_id' => $client->id,
        ]);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://example.test/wp-json/exotic-crm-sync/v1/clients/9302/delete');
    }

    public function test_bulk_delete_preview_applies_filters_and_caps_batches(): void
    {
        $platform = $this->createPlatform();
        $manager = $this->createManager($platform);

        Client::factory()->count(501)->create([
            'platform_id' => $platform->id,
            'profile_status' => 'private',
            'sb_user_id' => null,
            'last_online_at' => null,
        ]);

        $clientWithChat = $this->createClient($platform, 9901);
        $clientWithChat->update(['sb_user_id' => 'sb_123']);

        Sanctum::actingAs($manager);

        $response = $this->postJson('/api/crm/clients/bulk-delete/preview', [
            'filters' => [
                'platform_id' => $platform->id,
                'inactive_days' => 30,
                'has_no_chat' => true,
                'has_no_subscription_or_payment' => true,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('total_count', 501)
            ->assertJsonPath('capped', true);

        $this->assertCount(500, $response->json('clients'));
    }

    public function test_bulk_delete_removes_selected_clients_and_records_bulk_audit(): void
    {
        $platform = $this->createPlatform();
        $manager = $this->createManager($platform);
        $first = $this->createClient($platform, 9303);
        $second = $this->createClient($platform, -2);

        Http::fake([
            'https://example.test/wp-json/exotic-crm-sync/v1/clients/9303/delete' => Http::response([
                'deleted' => true,
            ], 200),
        ]);

        Sanctum::actingAs($manager);

        $this->postJson('/api/crm/clients/bulk-delete', [
            'client_ids' => [$first->id, $second->id],
            'confirm' => 'DELETE',
            'reason' => 'Clear dormant profiles',
        ])->assertOk()
            ->assertJsonPath('deleted_count', 2)
            ->assertJsonCount(0, 'failed');

        $this->assertDatabaseMissing('clients', ['id' => $first->id]);
        $this->assertDatabaseMissing('clients', ['id' => $second->id]);
        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'action' => CrmAuditAction::CLIENT_BULK_DELETE,
            'entity_type' => 'platform',
            'entity_id' => $platform->id,
        ]);
    }

    private function createPlatform(): Platform
    {
        return Platform::factory()->create([
            'name' => 'Deletion Market',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function createProduct(Platform $platform): Product
    {
        return Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Premium Plan',
            'display_name' => 'Premium Plan',
            'slug' => 'premium-plan-' . $platform->id,
            'tier' => 'premium',
            'weekly_price' => 800,
            'biweekly_price' => 1600,
            'monthly_price' => 3200,
            'currency' => 'KES',
            'is_active' => true,
        ]);
    }

    private function createClient(Platform $platform, int $wpPostId): Client
    {
        return Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => $wpPostId,
            'wp_user_id' => $wpPostId > 0 ? $wpPostId + 4000 : null,
            'profile_status' => 'private',
        ]);
    }

    private function createManager(Platform $platform): User
    {
        return User::factory()->create([
            'role' => 'sub_admin',
            'status' => 'active',
            'assigned_market_ids' => [$platform->id],
        ]);
    }
}
