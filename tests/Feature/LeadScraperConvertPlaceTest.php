<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Lead;
use App\Models\Platform;
use App\Models\User;
use App\Services\ClientSyncService;
use App\Services\WpDirectProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lead Scraper "convert & place": scraped places are cleaned and loosely matched to the
 * market's WordPress cities, and the reviewed bulk conversion provisions profiles there.
 */
class LeadScraperConvertPlaceTest extends TestCase
{
    use RefreshDatabase;

    private Platform $market;

    private const LOCATIONS = ['locations' => [
        ['id' => 1, 'name' => 'Nairobi', 'cities' => [['id' => 11, 'name' => 'Kilimani'], ['id' => 12, 'name' => 'Kileleshwa'], ['id' => 13, 'name' => 'South B']]],
        ['id' => 2, 'name' => 'Nakuru', 'cities' => [['id' => 21, 'name' => 'Nakuru town']]],
        ['id' => 3, 'name' => 'Kisumu', 'cities' => []],
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->market = Platform::factory()->create([
            'phone_prefix' => '254', 'db_host' => 'fixture', 'db_name' => 'fixture', 'db_user' => 'fixture', 'db_pass' => 'fixture',
            'wp_api_url' => 'https://wp-market.test/wp-json/exotic-crm-sync/v1', 'wp_api_user' => 'crm', 'wp_api_password' => 'secret',
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [$this->market->id]]));
        Http::fake(['https://wp-market.test/wp-json/exotic-crm-sync/v1/locations*' => Http::response(self::LOCATIONS, 200)]);
        // Our current supply: Kilimani is well covered, South B is thin.
        Client::factory()->count(4)->create(['platform_id' => $this->market->id, 'city' => 'Kilimani']);
        Client::factory()->create(['platform_id' => $this->market->id, 'city' => 'South B']);
    }

    public function test_places_are_sanitized_and_loosely_matched_with_thin_counts(): void
    {
        $data = $this->postJson('/api/crm/scraper/placements', [
            'platform_id' => $this->market->id,
            'places' => ['Kilimani Nairobi in Yaya Centre 💋', 'Kilelshwa, Nairobi', 'Nakuru', 'Nairobi', 'Kisumu Town', 'Mombasa'],
        ])->assertOk()->json();

        $m = collect($data['matches'])->keyBy('place');
        $this->assertSame('Kilimani Nairobi in Yaya Centre', $m['Kilimani Nairobi in Yaya Centre 💋']['clean']);
        $this->assertSame([11, 'close'], [$m['Kilimani Nairobi in Yaya Centre 💋']['match']['city_id'], $m['Kilimani Nairobi in Yaya Centre 💋']['confidence']]);
        $this->assertSame([12, 'guess'], [$m['Kilelshwa, Nairobi']['match']['city_id'], $m['Kilelshwa, Nairobi']['confidence']]);
        $this->assertSame([21, 'exact'], [$m['Nakuru']['match']['city_id'], $m['Nakuru']['confidence']]);
        // Region only → its thinnest city (Kileleshwa has 0 of ours).
        $this->assertSame('region', $m['Nairobi']['confidence']);
        $this->assertSame(0, $m['Nairobi']['match']['our_available']);
        $this->assertSame([3, null], [$m['Kisumu Town']['match']['region_id'], $m['Kisumu Town']['match']['city_id']]);
        $this->assertSame('none', $m['Mombasa']['confidence']);
        // Options come thinnest first and carry our counts.
        $this->assertSame(4, collect($data['options'])->firstWhere('city_id', 11)['our_available']);
        $this->assertSame(0, $data['options'][0]['our_available']);
    }

    public function test_reviewed_conversion_places_the_profile_in_the_chosen_wordpress_city(): void
    {
        $lead = Lead::create(['platform_id' => $this->market->id, 'name' => 'Amina', 'phone_normalized' => '254711000222', 'source' => 'import', 'status' => 'new']);

        $this->postJson('/api/crm/leads/bulk-convert/preview', ['lead_ids' => [$lead->id], 'placements' => [$lead->id => ['region_id' => 1, 'city_id' => 99]]])
            ->assertStatus(422)->assertJsonValidationErrors('placements');
        $this->postJson('/api/crm/leads/bulk-convert/preview', ['lead_ids' => [$lead->id], 'placements' => [$lead->id => ['region_id' => 1]]])
            ->assertStatus(422);

        $preview = $this->postJson('/api/crm/leads/bulk-convert/preview', ['lead_ids' => [$lead->id], 'placements' => [$lead->id => ['region_id' => 1, 'city_id' => 13]]])
            ->assertOk()->json('rows.0');
        $this->assertSame(['create', 'South B'], [$preview['action'], $preview['place']]);

        $provisioning = \Mockery::mock(WpDirectProvisioningService::class);
        $provisioning->shouldReceive('provisionEscort')->once()
            ->withArgs(fn (array $p) => $p['region_id'] === 1 && $p['city_id'] === 13 && $p['city'] === 'South B' && $p['provision_request_id'] === 'lead-client-'.$lead->id)
            ->andReturn(['wp_post_id' => 501, 'wp_user_id' => 601, 'wp_post_status' => 'private']);
        $this->app->bind(WpDirectProvisioningService::class, fn () => $provisioning);
        $sync = \Mockery::mock(ClientSyncService::class);
        $sync->shouldReceive('syncOne')->andThrow(new \RuntimeException('Fixture: no external sync'));
        $this->app->bind(ClientSyncService::class, fn () => $sync);

        $this->postJson('/api/crm/leads/'.$lead->id.'/bulk-convert', ['review_token' => $preview['review_token']])
            ->assertOk()->assertJsonPath('status', 'created')->assertJsonPath('city', 'South B');
        $this->assertSame('South B', Client::query()->where('wp_post_id', 501)->value('city'));
    }
}
