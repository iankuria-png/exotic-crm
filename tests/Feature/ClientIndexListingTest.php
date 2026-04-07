<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
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
