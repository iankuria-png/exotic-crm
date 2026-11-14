<?php

namespace Tests\Feature;

use App\Models\ContactUnlockEvent;
use App\Models\Platform;
use App\Models\User;
use App\Services\ContactUnlockAccessService;
use App\Services\ContactUnlockDemandService;
use App\Services\ContactUnlockEventService;
use App\Services\ContactUnlockPricingService;
use App\Services\ContactUnlockPulseService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ContactUnlockCountryDemandTest extends TestCase
{
    private ContactUnlockDemandService $demand;

    protected function setUp(): void
    {
        parent::setUp();
        // Never migrate or reset the shared Local database. This connection is disposable.
        config(['database.default' => 'visitor_demand_test', 'database.connections.visitor_demand_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        Schema::create('platforms', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('country')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('platform_id');
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->string('city')->nullable();
        });
        Schema::create('user_platforms', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('platform_id');
        });
        Schema::create('contact_unlock_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('platform_id');
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('event_type');
            $table->string('session_hash');
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->unsignedBigInteger('visitor_contact_unlock_id')->nullable();
            $table->string('scope')->nullable();
            $table->string('pageview_id')->nullable();
            $table->string('visitor_phone_hash')->nullable();
            $table->string('event_id_hash')->nullable()->unique();
            $table->string('referrer_host')->nullable();
            $table->string('traffic_source')->nullable();
            $table->integer('local_hour')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
        $pricing = Mockery::mock(ContactUnlockPricingService::class);
        $pricing->shouldReceive('globallyEnabled')->andReturn(false);
        $pricing->shouldReceive('enabledMarketIds')->andReturn([]);
        $this->demand = new ContactUnlockDemandService($pricing);
        $this->app->instance(ContactUnlockDemandService::class, $this->demand);
    }

    public function test_disabled_sites_have_attempts_grouped_by_country_and_site_without_claiming_unique_people(): void
    {
        $this->site(1, 'Tanzania English', 'Tanzania');
        $this->site(2, 'Tanzania Swahili', 'tanzania');
        $this->site(3, 'Ghana', 'Ghana');
        $this->event(1, 'same-browser');
        $this->event(1, 'same-browser');
        $this->event(2, 'same-browser');
        $this->event(2, 'another-browser', ContactUnlockEvent::TYPE_ELIGIBLE_VIEW);
        $this->event(3, 'ghana-browser');

        $data = $this->summary($this->demand->countryScope(null, ' TANZANIA '));

        $this->assertSame(3, $data['totals']['recorded_attempts']);
        $this->assertSame(2, $data['totals']['site_sessions']);
        $this->assertCount(1, $data['countries']);
        $this->assertCount(2, $data['countries'][0]['sites']);
        $this->assertFalse($data['countries'][0]['sites'][0]['checkout_configured']);
        $this->assertSame('unavailable', $data['visitor_locations']['status']);
        $this->assertStringContainsString('not unique people', $data['session_definition']);
    }

    public function test_country_filter_intersects_authorisation_and_platform_and_cannot_widen_an_empty_scope(): void
    {
        $this->site(1, 'Tanzania English', 'Tanzania');
        $this->site(2, 'Tanzania Swahili', 'Tanzania');
        $this->site(3, 'Ghana', 'Ghana');
        $this->event(1, 'allowed');
        $this->event(2, 'forbidden');

        $this->assertSame([1], $this->demand->countryScope([1, 3], 'Tanzania'));
        $this->assertSame([], $this->demand->countryScope(3, 'Tanzania'));
        $this->assertSame([], $this->demand->countryScope([], 'Tanzania'));
        $this->assertSame([], $this->demand->countryScope([1], 'Unknown country'));
        $this->assertSame(1, $this->summary([1])['totals']['recorded_attempts']);
        $this->assertSame(0, $this->summary([])['totals']['sites']);
        $this->assertCount(0, $this->summary([])['profile_locations']);
    }

    public function test_missing_collection_is_unavailable_and_inactive_sites_stay_visible(): void
    {
        $this->site(1, 'Tanzania English', 'Tanzania');
        $this->site(2, 'Tanzania Swahili', 'Tanzania', false);
        $this->event(1, 'old', ContactUnlockEvent::TYPE_CTA_CLICK, '2026-10-01 12:00:00');

        $data = $this->summary(null);
        $sites = collect($data['countries'][0]['sites'])->keyBy('platform_id');
        $this->assertSame('observed_partial', $sites[1]['coverage']);
        $this->assertSame(0, $sites[1]['recorded_attempts']);
        $this->assertSame('unavailable', $sites[2]['coverage']);
        $this->assertFalse($sites[2]['site_active']);
        $this->assertSame(1, $data['totals']['sites_with_event_history']);
        $this->assertStringContainsString('not proof of zero demand', $data['coverage_note']);
    }

    public function test_locations_are_cta_only_current_profile_cities_and_bad_cross_site_links_are_unknown(): void
    {
        $this->site(1, 'Ghana', 'Ghana');
        $this->site(2, 'Tanzania', 'Tanzania');
        DB::table('clients')->insert([
            ['id' => 10, 'platform_id' => 1, 'city' => 'Accra'],
            ['id' => 20, 'platform_id' => 2, 'city' => 'Dar es Salaam'],
        ]);
        $this->event(1, 'one', client: 10);
        $this->event(1, 'two', client: 20);
        $this->event(1, 'view', ContactUnlockEvent::TYPE_ELIGIBLE_VIEW, client: 10);
        $this->event(1, 'out-of-range', at: '2026-11-01 12:00:00', client: 10);

        $data = $this->summary([1]);
        $locations = collect($data['profile_locations'])->keyBy('city');
        $this->assertSame(1, $locations['Accra']['recorded_attempts']);
        $this->assertSame(1, $locations['Unknown profile city']['recorded_attempts']);
        $this->assertFalse($locations->has('Dar es Salaam'));
        $this->assertSame(2, $data['totals']['recorded_attempts']);
        $this->assertStringContainsString('current cached', $data['location_definition']);
    }

    public function test_pulse_endpoint_intersects_country_with_sales_assignments_and_rejects_forbidden_site(): void
    {
        $this->site(1, 'Tanzania English', 'Tanzania');
        $this->site(2, 'Tanzania Swahili', 'Tanzania');
        $this->site(3, 'Ghana', 'Ghana');
        $sales = new User(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [1, 3]]);
        $sales->id = 99;
        Sanctum::actingAs($sales);
        $pulse = Mockery::mock(ContactUnlockPulseService::class);
        $pulse->shouldReceive('summary')->once()->with([1], '30d', null, null, null, null)->andReturn(['accepted_scope' => [1]]);
        $pulse->shouldReceive('summary')->once()->with([], 'today', null, null, null, null)->andReturn(['accepted_scope' => []]);
        $this->app->instance(ContactUnlockPulseService::class, $pulse);

        $this->getJson('/api/crm/settings/billing/contact-unlock/pulse?country=Tanzania&range=30d')
            ->assertOk()->assertJsonPath('accepted_scope', [1]);
        $this->getJson('/api/crm/settings/billing/contact-unlock/pulse?country=Tanzania&platform_id=3')
            ->assertOk()->assertJsonPath('accepted_scope', []);
        $this->getJson('/api/crm/settings/billing/contact-unlock/pulse?country=Tanzania&platform_id=2')->assertForbidden();
    }

    public function test_pulse_endpoint_accepts_both_tanzania_sites_for_admin(): void
    {
        $this->site(1, 'Tanzania English', 'Tanzania');
        $this->site(2, 'Tanzania Swahili', 'Tanzania');
        $admin = new User(['role' => 'admin', 'status' => 'active']);
        $admin->id = 100;
        Sanctum::actingAs($admin);
        $pulse = Mockery::mock(ContactUnlockPulseService::class);
        $pulse->shouldReceive('summary')->once()->with([1, 2], 'today', null, null, null, null)->andReturn(['accepted_scope' => [1, 2]]);
        $this->app->instance(ContactUnlockPulseService::class, $pulse);
        $this->getJson('/api/crm/settings/billing/contact-unlock/pulse?country=Tanzania')->assertOk()->assertJsonPath('accepted_scope', [1, 2]);
    }

    public function test_pulse_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/crm/settings/billing/contact-unlock/pulse?country=Tanzania')->assertUnauthorized();
    }

    public function test_requested_location_limit_is_explicit_and_does_not_truncate_attempt_totals(): void
    {
        $this->site(1, 'Ghana', 'Ghana');
        for ($id = 1; $id <= 101; $id++) {
            DB::table('clients')->insert(['id' => $id, 'platform_id' => 1, 'city' => 'City '.$id]);
            $this->event(1, 'session-'.$id, client: $id);
        }

        $data = $this->summary([1]);
        $this->assertSame(101, $data['totals']['recorded_attempts']);
        $this->assertSame(101, $data['totals']['site_sessions']);
        $this->assertCount(100, $data['profile_locations']);
        $this->assertSame(['limit' => 100, 'total' => 101, 'truncated' => true], $data['profile_location_meta']);
    }

    public function test_existing_event_ingestion_records_and_deduplicates_interest_with_checkout_disabled(): void
    {
        CarbonImmutable::setTestNow('2026-11-13 12:00:00');
        $this->travelTo(now()->setDate(2026, 11, 13)->setTime(12, 0));
        $this->site(1, 'Tanzania', 'Tanzania');
        DB::table('clients')->insert(['id' => 10, 'platform_id' => 1, 'wp_post_id' => 99, 'city' => 'Arusha']);
        $ingest = new ContactUnlockEventService(Mockery::mock(ContactUnlockAccessService::class));
        $platform = Platform::query()->findOrFail(1);
        $first = $ingest->record($platform, 99, 'event-one', ContactUnlockEvent::TYPE_CTA_CLICK, 'browser-proof');
        $replay = $ingest->record($platform, 99, 'event-one', ContactUnlockEvent::TYPE_CTA_CLICK, 'browser-proof');
        $data = $this->summary([1]);

        $this->assertFalse($first['deduped']);
        $this->assertTrue($replay['deduped']);
        $this->assertSame(1, $data['totals']['recorded_attempts']);
        $this->assertSame(1, $data['totals']['site_sessions']);
        $this->assertFalse($data['countries'][0]['sites'][0]['checkout_configured']);
        $this->assertSame('Arusha', $data['profile_locations'][0]['city']);
        $this->travelBack();
        CarbonImmutable::setTestNow();
    }

    private function summary(int|array|null $scope): array
    {
        return $this->demand->summary($scope, CarbonImmutable::parse('2026-11-13 00:00:00'), CarbonImmutable::parse('2026-11-13 23:59:59'));
    }

    private function site(int $id, string $name, string $country, bool $active = true): void
    {
        DB::table('platforms')->insert(['id' => $id, 'name' => $name, 'country' => $country, 'is_active' => $active]);
    }

    private function event(int $site, string $session, string $type = ContactUnlockEvent::TYPE_CTA_CLICK, string $at = '2026-11-13 12:00:00', ?int $client = null): void
    {
        DB::table('contact_unlock_events')->insert(['platform_id' => $site, 'client_id' => $client, 'event_type' => $type, 'session_hash' => $session, 'occurred_at' => $at]);
    }
}
