<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientIndexListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_clients_index_uses_active_subscription_as_canonical_plan_source(): void
    {
        $platform = $this->createPlatform();
        $admin = $this->createAdminUser();
        $vvipProduct = $this->createProduct($platform, 'VVIP Showcase', 'vvip');
        $vipProduct = $this->createProduct($platform, 'VIP Spotlight', 'vip');

        $vvipClient = Client::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'VVIP Client',
            'premium' => false,
            'featured' => false,
        ]);
        $vipClient = Client::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'VIP Client',
            'premium' => false,
            'featured' => false,
        ]);
        $legacyPremiumClient = Client::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Legacy Premium Client',
            'premium' => true,
            'featured' => false,
        ]);
        $legacyFeaturedClient = Client::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Legacy Featured Client',
            'premium' => false,
            'featured' => true,
        ]);

        Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $vvipClient->id,
            'product_id' => $vvipProduct->id,
            'plan_type' => 'vip',
            'status' => 'active',
        ]);
        Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $vipClient->id,
            'product_id' => $vipProduct->id,
            'plan_type' => 'vip',
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        $indexResponse = $this->getJson("/api/crm/clients?platform_id={$platform->id}");
        $indexResponse->assertOk();

        $plansByName = collect($indexResponse->json('data'))
            ->mapWithKeys(fn (array $row) => [$row['name'] => [
                'key' => $row['plan_key'] ?? null,
                'label' => $row['plan_label'] ?? null,
            ]])
            ->all();

        $this->assertSame('vvip', data_get($plansByName, 'VVIP Client.key'));
        $this->assertSame('VVIP', data_get($plansByName, 'VVIP Client.label'));
        $this->assertSame('vip', data_get($plansByName, 'VIP Client.key'));
        $this->assertSame('VIP', data_get($plansByName, 'VIP Client.label'));
        $this->assertSame('premium', data_get($plansByName, 'Legacy Premium Client.key'));
        $this->assertSame('Premium', data_get($plansByName, 'Legacy Premium Client.label'));
        $this->assertSame('featured', data_get($plansByName, 'Legacy Featured Client.key'));
        $this->assertSame('Featured', data_get($plansByName, 'Legacy Featured Client.label'));

        $vvipResponse = $this->getJson("/api/crm/clients?platform_id={$platform->id}&plan=vvip");
        $vvipResponse->assertOk();
        $this->assertSame([$vvipClient->id], collect($vvipResponse->json('data'))->pluck('id')->all());

        $vipResponse = $this->getJson("/api/crm/clients?platform_id={$platform->id}&plan=vip");
        $vipResponse->assertOk();
        $this->assertSame([$vipClient->id], collect($vipResponse->json('data'))->pluck('id')->all());

        $premiumResponse = $this->getJson("/api/crm/clients?platform_id={$platform->id}&plan=premium");
        $premiumResponse->assertOk();
        $this->assertSame([$legacyPremiumClient->id], collect($premiumResponse->json('data'))->pluck('id')->all());

        $featuredResponse = $this->getJson("/api/crm/clients?platform_id={$platform->id}&plan=featured");
        $featuredResponse->assertOk();
        $this->assertSame([$legacyFeaturedClient->id], collect($featuredResponse->json('data'))->pluck('id')->all());
    }

    public function test_clients_index_filters_created_date_range_and_reports_new_user_stats(): void
    {
        $platform = $this->createPlatform();
        $admin = $this->createAdminUser();
        $timezone = config('app.timezone');
        Carbon::setTestNow(Carbon::create(2026, 4, 7, 12, 0, 0, $timezone));

        try {
            $inRangeStart = Client::factory()->create([
                'platform_id' => $platform->id,
                'name' => 'Range Start Client',
                'created_at' => Carbon::create(2026, 4, 1, 0, 0, 0, $timezone),
                'updated_at' => Carbon::create(2026, 4, 1, 0, 0, 0, $timezone),
            ]);
            $inRangeEnd = Client::factory()->create([
                'platform_id' => $platform->id,
                'name' => 'Range End Client',
                'created_at' => Carbon::create(2026, 4, 7, 23, 59, 59, $timezone),
                'updated_at' => Carbon::create(2026, 4, 7, 23, 59, 59, $timezone),
            ]);
            Client::factory()->create([
                'platform_id' => $platform->id,
                'name' => 'Outside Range Client',
                'created_at' => Carbon::create(2026, 3, 31, 23, 59, 59, $timezone),
                'updated_at' => Carbon::create(2026, 3, 31, 23, 59, 59, $timezone),
            ]);

            Sanctum::actingAs($admin);

            $filteredResponse = $this->getJson("/api/crm/clients?platform_id={$platform->id}&created_from=2026-04-01&created_to=2026-04-07");
            $filteredResponse->assertOk()
                ->assertJsonPath('stats.new_users', 2)
                ->assertJsonPath('stats.total', 2);

            $this->assertEqualsCanonicalizing(
                [$inRangeStart->id, $inRangeEnd->id],
                collect($filteredResponse->json('data'))->pluck('id')->all()
            );

            $defaultStatsResponse = $this->getJson("/api/crm/clients?platform_id={$platform->id}");
            $defaultStatsResponse->assertOk()
                ->assertJsonPath('stats.new_users', 2);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_clients_index_can_filter_high_risk_clients(): void
    {
        $platform = $this->createPlatform();
        $admin = $this->createAdminUser();

        $highRiskClient = Client::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'High Risk Client',
            'is_high_risk' => true,
            'risk_reason_code' => 'fraud_suspected',
        ]);
        Client::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Normal Client',
            'is_high_risk' => false,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/crm/clients?platform_id={$platform->id}&high_risk=1");

        $response->assertOk()
            ->assertJsonPath('stats.total', 1)
            ->assertJsonPath('stats.high_risk', 1)
            ->assertJsonPath('data.0.id', $highRiskClient->id)
            ->assertJsonPath('data.0.is_high_risk', true)
            ->assertJsonPath('data.0.risk_reason_code', 'fraud_suspected');
    }

    public function test_clients_index_exposes_cached_display_image_url(): void
    {
        $platform = $this->createPlatform();
        $admin = $this->createAdminUser();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Image Ready Client',
            'main_image_url' => null,
            'display_image_url' => 'https://cdn.example.test/profiles/image-ready.webp',
            'display_image_source' => 'wp_media_first',
            'display_image_checked_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/crm/clients?platform_id={$platform->id}");

        $response->assertOk()
            ->assertJsonPath('data.0.id', $client->id)
            ->assertJsonPath('data.0.display_image_url', 'https://cdn.example.test/profiles/image-ready.webp')
            ->assertJsonPath('data.0.display_image_source', 'wp_media_first');
    }

    public function test_clients_index_supports_name_and_signup_sorting(): void
    {
        $platform = $this->createPlatform();
        $admin = $this->createAdminUser();
        $timezone = config('app.timezone');

        Client::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Charlie Client',
            'created_at' => Carbon::create(2026, 4, 1, 10, 0, 0, $timezone),
            'updated_at' => Carbon::create(2026, 4, 1, 10, 0, 0, $timezone),
        ]);
        Client::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Alice Client',
            'created_at' => Carbon::create(2026, 4, 3, 10, 0, 0, $timezone),
            'updated_at' => Carbon::create(2026, 4, 3, 10, 0, 0, $timezone),
        ]);
        Client::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Brenda Client',
            'created_at' => Carbon::create(2026, 4, 2, 10, 0, 0, $timezone),
            'updated_at' => Carbon::create(2026, 4, 2, 10, 0, 0, $timezone),
        ]);

        Sanctum::actingAs($admin);

        $nameResponse = $this->getJson("/api/crm/clients?platform_id={$platform->id}&sort_by=name&sort_direction=asc");
        $nameResponse->assertOk();
        $this->assertSame(
            ['Alice Client', 'Brenda Client', 'Charlie Client'],
            collect($nameResponse->json('data'))->pluck('name')->all()
        );

        $createdResponse = $this->getJson("/api/crm/clients?platform_id={$platform->id}&sort_by=created_at&sort_direction=desc");
        $createdResponse->assertOk();
        $this->assertSame(
            ['Alice Client', 'Brenda Client', 'Charlie Client'],
            collect($createdResponse->json('data'))->pluck('name')->all()
        );
    }

    public function test_conversion_queue_respects_requested_platform_filter(): void
    {
        $kenya = $this->createPlatform();
        $ghana = Platform::factory()->create([
            'name' => 'Ghana Market',
            'country' => 'Ghana',
            'phone_prefix' => '233',
            'currency_code' => 'GHS',
            'timezone' => 'Africa/Accra',
        ]);
        $admin = $this->createAdminUser();
        $timezone = config('app.timezone');
        Carbon::setTestNow(Carbon::create(2026, 5, 22, 12, 0, 0, $timezone));

        try {
            $kenyaClient = Client::factory()->create([
                'platform_id' => $kenya->id,
                'name' => 'Kenya Queue Client',
                'first_contact_at' => null,
                'created_at' => now()->subMinutes(10),
                'updated_at' => now()->subMinutes(10),
            ]);
            Client::factory()->create([
                'platform_id' => $ghana->id,
                'name' => 'Ghana Queue Client',
                'first_contact_at' => null,
                'created_at' => now()->subMinutes(10),
                'updated_at' => now()->subMinutes(10),
            ]);

            $kenyaFailedClient = Client::factory()->create([
                'platform_id' => $kenya->id,
                'name' => 'Kenya Failed Payment Client',
                'first_contact_at' => now()->subHours(2),
                'last_contact_at' => now()->subHours(2),
            ]);
            $ghanaFailedClient = Client::factory()->create([
                'platform_id' => $ghana->id,
                'name' => 'Ghana Failed Payment Client',
                'first_contact_at' => now()->subHours(2),
                'last_contact_at' => now()->subHours(2),
            ]);
            $kenyaPayment = Payment::factory()->create([
                'platform_id' => $kenya->id,
                'client_id' => $kenyaFailedClient->id,
                'status' => 'failed',
                'reconciliation_state' => 'open',
                'created_at' => now()->subMinutes(20),
                'updated_at' => now()->subMinutes(20),
            ]);
            Payment::factory()->create([
                'platform_id' => $ghana->id,
                'client_id' => $ghanaFailedClient->id,
                'status' => 'failed',
                'reconciliation_state' => 'open',
                'created_at' => now()->subMinutes(20),
                'updated_at' => now()->subMinutes(20),
            ]);

            Sanctum::actingAs($admin);

            $response = $this->getJson("/api/crm/clients/conversion-queue?platform_id={$kenya->id}");
            $response->assertOk()
                ->assertJsonPath('counts.new_signups', 1)
                ->assertJsonPath('counts.failed_payments', 1)
                ->assertJsonPath('new_signups.0.id', $kenyaClient->id)
                ->assertJsonPath('new_signups.0.platform.id', $kenya->id)
                ->assertJsonPath('failed_payments.0.id', $kenyaPayment->id)
                ->assertJsonPath('failed_payments.0.client.platform.id', $kenya->id);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_conversion_queue_reports_real_totals_and_allows_expanded_signup_limit(): void
    {
        $platform = $this->createPlatform();
        $admin = $this->createAdminUser();
        $timezone = config('app.timezone');
        Carbon::setTestNow(Carbon::create(2026, 5, 22, 12, 0, 0, $timezone));

        try {
            Client::factory()->count(55)->create([
                'platform_id' => $platform->id,
                'first_contact_at' => null,
                'created_at' => now()->subMinutes(30),
                'updated_at' => now()->subMinutes(30),
            ]);

            Sanctum::actingAs($admin);

            $defaultResponse = $this->getJson("/api/crm/clients/conversion-queue?platform_id={$platform->id}&range_hours=24");
            $defaultResponse->assertOk()
                ->assertJsonPath('counts.new_signups', 55)
                ->assertJsonPath('visible_counts.new_signups', 50)
                ->assertJsonPath('limits.new_signups', 50)
                ->assertJsonPath('has_more.new_signups', true)
                ->assertJsonCount(50, 'new_signups');

            $expandedResponse = $this->getJson("/api/crm/clients/conversion-queue?platform_id={$platform->id}&range_hours=24&new_signups_limit=75");
            $expandedResponse->assertOk()
                ->assertJsonPath('counts.new_signups', 55)
                ->assertJsonPath('visible_counts.new_signups', 55)
                ->assertJsonPath('limits.new_signups', 75)
                ->assertJsonPath('has_more.new_signups', false)
                ->assertJsonCount(55, 'new_signups');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_closed_reasons_summary_groups_patterns_for_selected_market(): void
    {
        $platform = $this->createPlatform();
        $otherPlatform = Platform::factory()->create([
            'name' => 'Ghana Market',
            'country' => 'Ghana',
            'phone_prefix' => '233',
            'currency_code' => 'GHS',
            'timezone' => 'Africa/Accra',
        ]);
        $admin = $this->createAdminUser();
        $timezone = config('app.timezone');
        Carbon::setTestNow(Carbon::create(2026, 5, 22, 12, 0, 0, $timezone));

        try {
            Client::factory()->count(2)->create([
                'platform_id' => $platform->id,
                'closed_at' => now()->subDays(2),
                'close_reason_code' => 'no_response',
                'closed_by' => $admin->id,
                'purge_after' => now()->addDays(28),
            ]);
            Client::factory()->create([
                'platform_id' => $platform->id,
                'closed_at' => now()->subDays(3),
                'close_reason_code' => 'payment_issue',
                'close_reason_note' => 'Failed STK attempts and link expired.',
                'closed_by' => $admin->id,
                'purge_after' => now()->addDays(27),
            ]);
            Client::factory()->create([
                'platform_id' => $platform->id,
                'closed_at' => now()->subDays(35),
                'close_reason_code' => 'declined',
                'closed_by' => $admin->id,
                'purge_after' => now()->subDays(5),
            ]);
            Client::factory()->create([
                'platform_id' => $otherPlatform->id,
                'closed_at' => now()->subDay(),
                'close_reason_code' => 'no_response',
                'closed_by' => $admin->id,
                'purge_after' => now()->addDays(29),
            ]);

            Sanctum::actingAs($admin);

            $response = $this->getJson("/api/crm/clients/closed-reasons-summary?platform_id={$platform->id}&range_days=30");
            $response->assertOk()
                ->assertJsonPath('totals.closed', 3)
                ->assertJsonPath('totals.previous_closed', 1)
                ->assertJsonPath('totals.delta', 2)
                ->assertJsonPath('totals.with_notes', 1)
                ->assertJsonPath('top_reason.code', 'no_response')
                ->assertJsonPath('top_reason.count', 2)
                ->assertJsonPath('top_reason.share', 66.7)
                ->assertJsonPath('recent_notes.0.reason_code', 'payment_issue')
                ->assertJsonPath('recent_notes.0.reason_note', 'Failed STK attempts and link expired.');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_conversion_queue_respects_sales_assigned_market_scope(): void
    {
        $kenya = $this->createPlatform();
        $ghana = Platform::factory()->create([
            'name' => 'Ghana Market',
            'country' => 'Ghana',
            'phone_prefix' => '233',
            'currency_code' => 'GHS',
            'timezone' => 'Africa/Accra',
        ]);
        $sales = User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [$kenya->id],
            'email' => 'client-queue-sales-' . uniqid('', true) . '@example.test',
        ]);
        $timezone = config('app.timezone');
        Carbon::setTestNow(Carbon::create(2026, 5, 22, 12, 0, 0, $timezone));

        try {
            $kenyaClient = Client::factory()->create([
                'platform_id' => $kenya->id,
                'name' => 'Assigned Market Client',
                'first_contact_at' => null,
                'created_at' => now()->subMinutes(10),
                'updated_at' => now()->subMinutes(10),
            ]);
            Client::factory()->create([
                'platform_id' => $ghana->id,
                'name' => 'Out Of Scope Market Client',
                'first_contact_at' => null,
                'created_at' => now()->subMinutes(10),
                'updated_at' => now()->subMinutes(10),
            ]);

            Sanctum::actingAs($sales);

            $response = $this->getJson('/api/crm/clients/conversion-queue');
            $response->assertOk()
                ->assertJsonPath('counts.new_signups', 1)
                ->assertJsonPath('new_signups.0.id', $kenyaClient->id)
                ->assertJsonPath('new_signups.0.platform.id', $kenya->id);
        } finally {
            Carbon::setTestNow();
        }
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

    private function createProduct(Platform $platform, string $name, string $tier): Product
    {
        return Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => $name,
            'display_name' => $name,
            'slug' => Str::slug($name),
            'tier' => $tier,
            'weekly_price' => 1000,
            'biweekly_price' => 2000,
            'monthly_price' => 4000,
            'currency' => 'KES',
            'is_active' => true,
        ]);
    }

    private function createAdminUser(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => [],
            'email' => 'client-listing-admin-' . uniqid('', true) . '@example.test',
        ]);
    }
}
