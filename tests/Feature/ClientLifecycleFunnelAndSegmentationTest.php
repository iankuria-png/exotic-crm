<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientLifecycleFunnelAndSegmentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_summary_exposes_monotonic_client_funnel_and_annotations(): void
    {
        $platform = $this->createPlatform();
        $admin = $this->createAdminUser();
        $product = $this->createProduct($platform);

        $retained = $this->createClient($platform, [
            'name' => 'Retained Client',
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'seo_score' => 5,
        ]);
        $paidChurned = $this->createClient($platform, [
            'name' => 'Paid Churned Client',
            'profile_status' => 'private',
            'needs_payment' => true,
            'seo_score' => 5,
        ]);
        $this->createClient($platform, [
            'name' => 'Active Unpaid Client',
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'seo_score' => 5,
        ]);
        $paidOffPath = $this->createClient($platform, [
            'name' => 'Paid Off Path Client',
            'profile_status' => 'private',
            'needs_payment' => true,
            'main_image_url' => null,
            'display_image_url' => null,
            'seo_score' => null,
        ]);
        $failedOnly = $this->createClient($platform, [
            'name' => 'Failed Only Client',
            'profile_status' => 'private',
            'needs_payment' => true,
            'seo_score' => null,
        ]);

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $retained->id,
            'status' => 'completed',
        ]);
        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $paidChurned->id,
            'status' => 'completed',
        ]);
        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $paidOffPath->id,
            'status' => 'completed',
        ]);
        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $failedOnly->id,
            'status' => 'failed',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/crm/reports/summary?platform_id={$platform->id}");

        $response->assertOk()
            ->assertJsonPath('lead_funnel_stages.0.key', 'new')
            ->assertJsonPath('client_funnel_stages.0.key', 'new')
            ->assertJsonPath('client_funnel_totals.total', 5)
            ->assertJsonPath('client_funnel_totals.paid', 2)
            ->assertJsonPath('client_funnel_totals.retained', 1)
            ->assertJsonPath('paid_offpath', 1)
            ->assertJsonPath('active_unpaid', 1)
            ->assertJsonPath('payment_failed_only', 1)
            ->assertJsonPath('churned', 2);

        $stageCounts = collect($response->json('client_funnel_stages'))->pluck('count')->values();
        for ($index = 0; $index < $stageCounts->count() - 1; $index++) {
            $this->assertGreaterThanOrEqual($stageCounts[$index + 1], $stageCounts[$index]);
        }
    }

    public function test_client_segments_are_exhaustive_and_filterable(): void
    {
        $platform = $this->createPlatform();
        $admin = $this->createAdminUser();
        $product = $this->createProduct($platform);

        $active = $this->createClient($platform, [
            'name' => 'Active Segment',
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
        ]);
        $this->createClient($platform, [
            'name' => 'Suspended Segment',
            'profile_status' => 'private',
            'needs_payment' => true,
            'is_high_risk' => true,
        ]);
        $this->createClient($platform, [
            'name' => 'Duplicate Segment',
            'profile_status' => 'private',
            'needs_payment' => true,
            'duplicate_of' => $active->id,
        ]);
        $churned = $this->createClient($platform, [
            'name' => 'Churned Segment',
            'profile_status' => 'private',
            'needs_payment' => true,
        ]);
        $this->createClient($platform, [
            'name' => 'Verification Segment',
            'profile_status' => 'private',
            'needs_payment' => true,
            'verified' => false,
            'kyc_required' => true,
        ]);
        $neverPaid = $this->createClient($platform, [
            'name' => 'Never Paid Segment',
            'profile_status' => 'private',
            'needs_payment' => true,
            'verified' => false,
            'kyc_required' => false,
        ]);

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $churned->id,
            'status' => 'completed',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/crm/clients?platform_id={$platform->id}");

        $response->assertOk()
            ->assertJsonPath('stats.total', 6)
            ->assertJsonPath('stats.segments.active', 1)
            ->assertJsonPath('stats.segments.suspended', 1)
            ->assertJsonPath('stats.segments.duplicate', 1)
            ->assertJsonPath('stats.segments.churned', 1)
            ->assertJsonPath('stats.segments.verification_pending', 1)
            ->assertJsonPath('stats.segments.never_paid', 1)
            ->assertJsonPath('stats.segments.abandoned_other', 0);

        $segments = $response->json('stats.segments');
        $this->assertSame($response->json('stats.total'), array_sum($segments));

        $filtered = $this->getJson("/api/crm/clients?platform_id={$platform->id}&segment=never_paid");
        $filtered->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $neverPaid->id);

        $this->assertNotContains($active->id, collect($filtered->json('data'))->pluck('id')->all());
    }

    public function test_non_reportable_payments_do_not_create_paid_history(): void
    {
        $platform = $this->createPlatform();
        $admin = $this->createAdminUser();
        $product = $this->createProduct($platform);

        $sandboxClient = $this->createClient($platform, ['profile_status' => 'private', 'needs_payment' => true]);
        $manualReviewClient = $this->createClient($platform, ['profile_status' => 'private', 'needs_payment' => true]);
        $failedClient = $this->createClient($platform, ['profile_status' => 'private', 'needs_payment' => true]);

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $sandboxClient->id,
            'status' => 'completed',
            'provider_environment' => 'sandbox',
            'payment_data' => ['test_mode' => true],
        ]);
        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $manualReviewClient->id,
            'status' => 'completed',
            'reconciliation_state' => 'manual_review',
        ]);
        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $failedClient->id,
            'status' => 'failed',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/crm/clients?platform_id={$platform->id}");

        $response->assertOk()
            ->assertJsonPath('stats.segments.churned', 0)
            ->assertJsonPath('stats.segments.never_paid', 3);
    }

    private function createPlatform(): Platform
    {
        return Platform::factory()->create([
            'name' => 'Kenya Market',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'timezone' => 'Africa/Nairobi',
        ]);
    }

    private function createAdminUser(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => [],
            'email' => 'lifecycle-admin-' . uniqid('', true) . '@example.test',
        ]);
    }

    private function createProduct(Platform $platform): Product
    {
        return Product::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Lifecycle Test Product ' . uniqid('', true),
            'display_name' => 'Lifecycle Test Product',
            'slug' => 'lifecycle-test-product-' . uniqid(),
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

    private function createClient(Platform $platform, array $overrides = []): Client
    {
        return Client::factory()->create(array_merge([
            'platform_id' => $platform->id,
            'profile_status' => 'private',
            'needs_payment' => true,
            'notactive' => false,
            'is_high_risk' => false,
            'verified' => false,
            'kyc_required' => false,
            'duplicate_of' => null,
            'main_image_url' => 'https://cdn.example.test/profile.jpg',
            'display_image_url' => null,
            'seo_score' => null,
        ], $overrides));
    }
}
