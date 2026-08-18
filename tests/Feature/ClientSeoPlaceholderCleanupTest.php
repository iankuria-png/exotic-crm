<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Support\ClientLifecycleState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientSeoPlaceholderCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_clients_index_identifies_seo_placeholder_profiles(): void
    {
        $platform = $this->createPlatform();
        $otherPlatform = $this->createPlatform(['name' => 'Tanzania Market', 'country' => 'Tanzania']);
        $admin = $this->createAdminUser();
        $product = $this->createProduct($platform);

        $placeholder = $this->createPlaceholderClient($platform, [
            'name' => 'March Seo Filler',
            'main_image_url' => 'https://example.test/uploads/nancy.jpeg',
        ]);
        $engineRecovered = $this->createPlaceholderClient($platform, [
            'name' => 'Real SEO Recovery',
            'lifecycle_restored_at' => CarbonImmutable::parse('2026-08-18 10:00:00'),
        ]);
        $withExpiry = $this->createPlaceholderClient($platform, [
            'name' => 'Has Expiry',
            'escort_expire' => now()->addDays(30)->timestamp,
        ]);
        $activeForeverPlaceholder = $this->createPlaceholderClient($platform, [
            'name' => 'Active Forever Placeholder',
            'lifecycle_state' => ClientLifecycleState::ACTIVE,
        ]);
        $outsideWindow = $this->createPlaceholderClient($platform, [
            'name' => 'Outside Import Window',
            'created_at' => CarbonImmutable::parse('2026-04-18 12:00:00'),
            'wp_created_at' => CarbonImmutable::parse('2026-04-18 12:00:00'),
        ]);
        $crmProvisioned = $this->createPlaceholderClient($platform, [
            'name' => 'CRM Provisioned',
            'signup_source' => 'crm_provisioned',
        ]);
        $paid = $this->createPlaceholderClient($platform, ['name' => 'Paid Real Client']);
        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $paid->id,
            'status' => 'completed',
        ]);
        $this->createPlaceholderClient($otherPlatform, ['name' => 'Other Market Placeholder']);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/crm/clients?platform_id={$platform->id}&segment=seo_placeholder");

        $response->assertOk()
            ->assertJsonPath('stats.segments.seo_placeholder', 2)
            ->assertJsonCount(2, 'data');

        $returnedIds = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($placeholder->id, $returnedIds);
        $this->assertContains($activeForeverPlaceholder->id, $returnedIds);
        $this->assertTrue((bool) collect($response->json('data'))->firstWhere('id', $placeholder->id)['seo_placeholder_candidate']);

        $this->assertFalse((bool) $engineRecovered->fresh()->seo_placeholder_candidate);
        $this->assertFalse((bool) $withExpiry->fresh()->seo_placeholder_candidate);
        $this->assertFalse((bool) $outsideWindow->fresh()->seo_placeholder_candidate);
        $this->assertFalse((bool) $crmProvisioned->fresh()->seo_placeholder_candidate);
    }

    public function test_bulk_takes_only_matching_seo_placeholders_private(): void
    {
        $platform = $this->createPlatform();
        $admin = $this->createAdminUser();
        $placeholder = $this->createPlaceholderClient($platform, [
            'name' => 'Legacy Seo Filler',
            'wp_post_id' => 93001,
            'main_image_url' => 'https://example.test/uploads/nancy.jpeg',
        ]);
        $engineRecovered = $this->createPlaceholderClient($platform, [
            'name' => 'Engine Restored Profile',
            'wp_post_id' => 93002,
            'lifecycle_restored_at' => CarbonImmutable::parse('2026-08-18 10:00:00'),
        ]);

        Http::fake([
            $platform->wp_api_url.'/clients/93001/deactivate' => Http::response(['success' => true], 200),
            $platform->wp_api_url.'/clients/93001' => Http::response($this->wpClientPayload($placeholder, 'private'), 200),
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/crm/clients/bulk-seo-placeholder-private', [
            'client_ids' => [$placeholder->id, $engineRecovered->id],
            'reason' => 'Clean up old SEO filler profiles',
        ])
            ->assertOk()
            ->assertJsonPath('summary.privatized', 1)
            ->assertJsonPath('summary.skipped', 1)
            ->assertJsonPath('summary.failed', 0);

        $this->assertDatabaseHas('clients', [
            'id' => $placeholder->id,
            'profile_status' => 'private',
        ]);
        $this->assertDatabaseHas('clients', [
            'id' => $engineRecovered->id,
            'profile_status' => 'publish',
        ]);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === $platform->wp_api_url.'/clients/93001/deactivate');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/clients/93002/deactivate'));
    }

    public function test_bulk_private_records_failure_when_wordpress_still_reports_public(): void
    {
        $platform = $this->createPlatform();
        $admin = $this->createAdminUser();
        $placeholder = $this->createPlaceholderClient($platform, [
            'name' => 'Stubborn Filler',
            'wp_post_id' => 93003,
        ]);

        Http::fake([
            $platform->wp_api_url.'/clients/93003/deactivate' => Http::response(['success' => true], 200),
            $platform->wp_api_url.'/clients/93003' => Http::response($this->wpClientPayload($placeholder, 'publish'), 200),
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/crm/clients/bulk-seo-placeholder-private', [
            'client_ids' => [$placeholder->id],
            'reason' => 'Clean up old SEO filler profiles',
        ])
            ->assertOk()
            ->assertJsonPath('summary.privatized', 0)
            ->assertJsonPath('summary.skipped', 0)
            ->assertJsonPath('summary.failed', 1)
            ->assertJsonPath('results.0.action', 'failed');

        $this->assertDatabaseHas('clients', [
            'id' => $placeholder->id,
            'profile_status' => 'publish',
        ]);
    }

    private function createPlaceholderClient(Platform $platform, array $overrides = []): Client
    {
        return Client::factory()->create(array_merge([
            'platform_id' => $platform->id,
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'lifecycle_state' => ClientLifecycleState::EXPIRED,
            'lifecycle_restored_at' => null,
            'main_image_url' => null,
            'display_image_url' => null,
            'escort_expire' => null,
            'premium_expire' => null,
            'featured_expire' => null,
            'premium' => false,
            'featured' => false,
            'signup_source' => null,
            'created_at' => CarbonImmutable::parse('2026-03-18 12:00:00'),
            'wp_created_at' => CarbonImmutable::parse('2026-03-18 12:00:00'),
        ], $overrides));
    }

    private function wpClientPayload(Client $client, string $postStatus): array
    {
        return [
            'wp_post_id' => (int) $client->wp_post_id,
            'wp_user_id' => (int) $client->wp_user_id,
            'name' => (string) $client->name,
            'phone' => (string) $client->phone_normalized,
            'email' => (string) $client->email,
            'city' => (string) $client->city,
            'post_status' => $postStatus,
            'premium' => false,
            'featured' => false,
            'premium_expire' => null,
            'featured_expire' => null,
            'escort_expire' => null,
            'verified' => false,
            'main_image_url' => (string) ($client->main_image_url ?? ''),
            'created_at' => CarbonImmutable::parse('2026-03-18 12:00:00')->toIso8601String(),
            'modified_at' => now()->toIso8601String(),
            'signup_source' => null,
        ];
    }

    private function createPlatform(array $overrides = []): Platform
    {
        $slug = strtolower(str_replace(' ', '-', $overrides['name'] ?? 'kenya-market')).'-'.uniqid();

        return Platform::factory()->create(array_merge([
            'name' => 'Kenya Market',
            'domain' => $slug.'.example.test',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'timezone' => 'Africa/Nairobi',
            'wp_api_url' => 'https://'.$slug.'.example.test/wp-json/exotic-crm-sync/v1',
        ], $overrides));
    }

    private function createAdminUser(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => [],
            'email' => 'seo-placeholder-admin-'.uniqid('', true).'@example.test',
        ]);
    }

    private function createProduct(Platform $platform): Product
    {
        return Product::query()->create([
            'platform_id' => $platform->id,
            'name' => 'SEO Placeholder Test Product '.uniqid('', true),
            'display_name' => 'SEO Placeholder Test Product',
            'slug' => 'seo-placeholder-test-product-'.uniqid(),
            'tier' => 'premium',
            'weekly_price' => 1000,
            'biweekly_price' => 2000,
            'monthly_price' => 4000,
            'currency' => 'KES',
            'is_active' => true,
            'is_public' => true,
            'is_archived' => false,
            'sort_order' => 0,
        ]);
    }
}
