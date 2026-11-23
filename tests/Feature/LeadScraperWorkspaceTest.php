<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Platform;
use App\Models\ScraperCapture;
use App\Models\ScraperRun;
use App\Models\ScraperSource;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\Scraper\Fetch\UrlSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadScraperWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private const HTML = ['content-type' => 'text/html; charset=utf-8'];

    protected function setUp(): void
    {
        parent::setUp();
        // No real DNS in tests: synthetic .test hosts resolve to nothing (allowed).
        $this->app->instance(UrlSafety::class, new UrlSafety(fn () => []));
        config(['scraper.limits.min_delay_ms' => 0, 'scraper.defaults.delay_ms' => 0]);
        Storage::fake('local');
    }

    public function test_probe_identifies_escortwp_stack_template_pagination_and_sample(): void
    {
        [$platform, $user] = $this->actingManager();
        Http::fake([
            'https://escortwp.test/robots.txt' => Http::response("User-agent: *\nDisallow: /wp-admin/\nSitemap: https://escortwp.test/wp-sitemap.xml\n", 200, ['content-type' => 'text/plain']),
            'https://escortwp.test/escorts/kenya/nairobi/' => Http::response($this->fixture('escortwp-page1.html'), 200, self::HTML + ['server' => 'cloudflare', 'cf-ray' => 'abc-NBO']),
        ]);

        $response = $this->postJson('/api/crm/scraper/probe', [
            'url' => 'https://escortwp.test/escorts/kenya/nairobi/',
            'platform_id' => $platform->id,
        ])->assertOk();

        $profile = $response->json('profile');
        $this->assertSame('ready', $profile['verdict']);
        $techKeys = array_column($profile['tech'], 'key');
        foreach (['cloudflare', 'wordpress', 'theme:escortwp', 'wp_rocket', 'jquery'] as $expected) {
            $this->assertContains($expected, $techKeys);
        }
        $this->assertSame('escortwp', $profile['template']['key']);
        $this->assertGreaterThanOrEqual(80, $profile['template']['confidence']);
        $this->assertSame(6, $profile['counts']['rows']);
        $this->assertSame(6, $profile['counts']['phones']);
        $this->assertSame('https://escortwp.test/escorts/kenya/nairobi/page/2/', $profile['pagination']['next_url']);
        $this->assertSame(3, $profile['pagination']['estimated_pages']);
        $this->assertSame('254700000001', $profile['sample'][0]['phone']);
        $this->assertSame('Model 1', $profile['sample'][0]['name']);
        $this->assertSame('Westlands, Nairobi', $profile['sample'][0]['city']);
        $this->assertSame('https://escortwp.test/wp-content/uploads/1.jpg', $profile['sample'][0]['image_url']);
        $this->assertSame([], $profile['blockers']);
    }

    public function test_probe_reports_cloudflare_challenge_with_human_overrides_only(): void
    {
        [$platform] = $this->actingManager();
        Http::fake([
            'https://challenged.test/robots.txt' => Http::response("User-agent: *\nAllow: /\n", 200, ['content-type' => 'text/plain']),
            'https://challenged.test/sitemap.xml' => Http::response('', 404),
            'https://challenged.test/sitemap_index.xml' => Http::response('', 404),
            'https://challenged.test/wp-sitemap.xml' => Http::response('', 404),
            'https://challenged.test/listing' => Http::response($this->fixture('cloudflare-challenge.html'), 403, self::HTML + ['server' => 'cloudflare', 'cf-ray' => 'x', 'cf-mitigated' => 'challenge']),
        ]);

        $profile = $this->postJson('/api/crm/scraper/probe', ['url' => 'https://challenged.test/listing', 'platform_id' => $platform->id])
            ->assertOk()->json('profile');

        $this->assertSame('blocked', $profile['verdict']);
        $this->assertSame('cloudflare_challenge', $profile['blockers'][0]['key']);
        $overrideKeys = array_column($profile['blockers'][0]['override_options'], 'key');
        $this->assertSame(['browser_capture', 'upload_html'], $overrideKeys);
        $this->assertNotContains('render_worker', $overrideKeys, 'Render worker must not be offered against a challenge.');
    }

    public function test_probe_flags_javascript_shell_and_unsafe_addresses(): void
    {
        [$platform] = $this->actingManager();
        Http::fake([
            'https://spa.test/girls' => Http::response($this->fixture('spa-shell.html'), 200, self::HTML),
            'https://spa.test/*' => Http::response('', 404),
        ]);

        $profile = $this->postJson('/api/crm/scraper/probe', ['url' => 'https://spa.test/girls', 'platform_id' => $platform->id])->assertOk()->json('profile');
        $this->assertSame('blocked', $profile['verdict']);
        $this->assertContains('js_required', array_column($profile['blockers'], 'key'));
        $this->assertContains('nextjs', array_column($profile['tech'], 'key'));

        $unsafe = $this->postJson('/api/crm/scraper/probe', ['url' => 'http://169.254.169.254/latest/meta-data', 'platform_id' => $platform->id])->assertOk()->json('profile');
        $this->assertSame('error', $unsafe['verdict']);
        $this->assertSame('unsafe_url', $unsafe['blockers'][0]['key']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
    }

    public function test_auto_detects_cards_when_no_template_matches(): void
    {
        [$platform] = $this->actingManager();
        Http::fake([
            'https://generic.test/robots.txt' => Http::response('', 404),
            'https://generic.test/ads' => Http::response($this->fixture('generic-cards.html'), 200, self::HTML),
        ]);

        $profile = $this->postJson('/api/crm/scraper/probe', ['url' => 'https://generic.test/ads', 'platform_id' => $platform->id])->assertOk()->json('profile');
        $this->assertNull($profile['template']);
        $this->assertSame('article.ad-card', $profile['detected_rules']['rules']['row_selector']);
        $this->assertSame(5, $profile['counts']['rows']);
        $this->assertSame('Lady 1', $profile['sample'][0]['name']);
        $this->assertSame('https://generic.test/ads/lady-1', $profile['sample'][0]['profile_url']);
        $this->assertSame('255700000001', $profile['sample'][0]['phone']);
    }

    public function test_laravel_family_reads_whatsapp_and_city_from_url(): void
    {
        [$platform] = $this->actingManager();
        Http::fake([
            'https://laravel-dir.test/robots.txt' => Http::response('', 404),
            'https://laravel-dir.test/escorts' => Http::response($this->fixture('laravel-list.html'), 200, self::HTML + ['set-cookie' => ['XSRF-TOKEN=abc; path=/', 'laraveldir_session=xyz; path=/; httponly']]),
        ]);

        $profile = $this->postJson('/api/crm/scraper/probe', ['url' => 'https://laravel-dir.test/escorts', 'platform_id' => $platform->id])->assertOk()->json('profile');
        $this->assertContains('laravel', array_column($profile['tech'], 'key'));
        $this->assertSame('laravel_escort_items', $profile['template']['key']);
        $this->assertSame('254711000001', $profile['sample'][0]['phone']);
        $this->assertSame('254711000001', $profile['sample'][0]['whatsapp']);
        $this->assertSame('Nakuru', $profile['sample'][0]['city']);
        $this->assertSame('https://laravel-dir.test/escorts?page=2', $profile['pagination']['next_url']);
    }

    public function test_multi_page_dry_run_then_selective_import(): void
    {
        [$platform, $user] = $this->actingManager();
        $this->fakeEscortWpSite();
        $site = $this->createSite($platform, $user, ['crawl_settings' => ['max_pages' => 5, 'delay_ms' => 0]]);

        $run = $this->postJson("/api/crm/scraper/sites/{$site->id}/runs", ['mode' => 'dry_run'])->assertStatus(202)->json('run');
        $run = $this->getJson("/api/crm/scraper/runs/{$run['id']}")->assertOk()->json('run');

        $this->assertSame('success', $run['status']);
        $this->assertSame(3, $run['pages_fetched']);
        $this->assertCount(15, $run['candidates']);
        $this->assertSame('last_page', $run['result']['stop_reason']);
        $this->assertSame(0, Lead::query()->count());

        // Existing lead becomes a duplicate at import time.
        Lead::query()->create(['platform_id' => $platform->id, 'name' => 'Already here', 'phone_normalized' => '254700000002', 'source' => 'import', 'status' => 'new']);

        $import = $this->postJson("/api/crm/scraper/runs/{$run['id']}/import", ['indexes' => [0, 1, 2]])->assertOk();
        $import->assertJsonPath('created', 2)->assertJsonPath('duplicates', 1);
        $this->assertDatabaseHas('leads', ['platform_id' => $platform->id, 'phone_normalized' => '254700000001', 'source_url' => 'https://escortwp.test/escort/model-1/']);
        $event = TimelineEvent::query()->where('event_type', 'lead_scraped')->first();
        $this->assertSame('Westlands, Nairobi', $event->content['city']);
        $this->assertSame((int) $site->id, $event->content['scraper_source_id']);

        // Re-importing the same rows does nothing.
        $this->postJson("/api/crm/scraper/runs/{$run['id']}/import", ['indexes' => [0, 1, 2]])->assertOk()->assertJsonPath('created', 0);
    }

    public function test_profile_follow_up_fills_missing_phones(): void
    {
        [$platform, $user] = $this->actingManager();
        Http::fake([
            'https://escortwp.test/robots.txt' => Http::response("User-agent: *\nAllow: /\n", 200, ['content-type' => 'text/plain']),
            'https://escortwp.test/escort/*' => Http::response($this->fixture('escortwp-profile.html'), 200, self::HTML),
            'https://escortwp.test/escorts/kenya/nairobi/' => Http::response($this->fixture('escortwp-nophones.html'), 200, self::HTML),
        ]);
        $site = $this->createSite($platform, $user, ['crawl_settings' => ['max_pages' => 1, 'follow_profiles' => true, 'max_profiles' => 2, 'delay_ms' => 0]]);

        $run = $this->postJson("/api/crm/scraper/sites/{$site->id}/runs", ['mode' => 'dry_run'])->assertStatus(202)->json('run');
        $run = ScraperRun::query()->findOrFail($run['id']);

        $withPhone = array_values(array_filter($run->preview, fn ($c) => $c['phone'] !== null));
        $this->assertCount(2, $withPhone, 'Only max_profiles profiles are opened.');
        $this->assertSame('254700000099', $withPhone[0]['phone']);
        $this->assertSame('254700000099', $withPhone[0]['whatsapp']);
    }

    public function test_import_run_creates_leads_and_cancel_is_respected(): void
    {
        [$platform, $user] = $this->actingManager();
        $this->fakeEscortWpSite();
        $site = $this->createSite($platform, $user, ['crawl_settings' => ['max_pages' => 1, 'delay_ms' => 0]]);

        $this->postJson("/api/crm/scraper/sites/{$site->id}/runs", ['mode' => 'import'])->assertStatus(202);
        $this->assertSame(6, Lead::query()->where('platform_id', $platform->id)->count());
        $this->assertSame('success', $site->fresh()->last_run_status);

        $queued = ScraperRun::query()->create(['scraper_source_id' => $site->id, 'platform_id' => $platform->id, 'initiated_by' => $user->id, 'mode' => 'dry_run', 'status' => 'queued']);
        $this->postJson("/api/crm/scraper/runs/{$queued->id}/cancel")->assertOk()->assertJsonPath('run.status', 'cancelled');
    }

    public function test_browser_capture_round_trip_feeds_a_capture_run(): void
    {
        [$platform, $user] = $this->actingManager();
        Http::fake(['*' => Http::response('', 500)]);
        $site = $this->createSite($platform, $user, [
            'source_url' => 'https://challenged.test/escorts/',
            'fetch_driver' => 'capture',
            'crawl_settings' => ['max_pages' => 1, 'delay_ms' => 0],
        ]);

        $created = $this->postJson("/api/crm/scraper/sites/{$site->id}/captures")->assertCreated();
        $this->assertStringStartsWith('javascript:', $created->json('bookmarklet'));
        preg_match('/token:"([A-Za-z0-9]+)"/', $created->json('bookmarklet'), $m);
        $token = $m[1];

        // Wrong host is refused; right host is stored once.
        $this->post('/api/crm/scraper/captures/receive', ['token' => $token, 'url' => 'https://elsewhere.test/', 'html' => '<html></html>'])->assertStatus(422);
        $this->post('/api/crm/scraper/captures/receive', [
            'token' => $token,
            'url' => 'https://www.challenged.test/escorts/',
            'html' => str_replace('escortwp.test', 'challenged.test', $this->fixture('escortwp-page1.html')),
        ])->assertOk()->assertSee('Sent to the CRM');
        $this->post('/api/crm/scraper/captures/receive', ['token' => $token, 'url' => 'https://challenged.test/escorts/', 'html' => '<html>x</html>'])->assertStatus(410);

        $capture = ScraperCapture::query()->firstOrFail();
        $this->assertSame('received', $capture->status);
        Storage::disk('local')->assertExists($capture->storage_path);

        $run = $this->postJson("/api/crm/scraper/sites/{$site->id}/runs", ['mode' => 'dry_run'])->assertStatus(202)->json('run');
        $run = ScraperRun::query()->findOrFail($run['id']);
        $this->assertSame('success', $run->status);
        $this->assertCount(6, $run->preview);
        Http::assertNothingSent();
    }

    public function test_upload_capture_and_permissions(): void
    {
        [$platform, $manager] = $this->actingManager();
        $site = $this->createSite($platform, $manager);

        $this->postJson("/api/crm/scraper/sites/{$site->id}/captures/upload", ['html' => 'not a page'])->assertStatus(422);
        $this->postJson("/api/crm/scraper/sites/{$site->id}/captures/upload", ['html' => $this->fixture('generic-cards.html')])
            ->assertCreated()->assertJsonPath('capture.method', 'paste')->assertJsonPath('capture.status', 'received');

        $other = $this->createPlatform('Uganda');
        $sales = $this->createUser('sales', [$other->id]);
        Sanctum::actingAs($sales);
        $this->getJson("/api/crm/scraper/sites/{$site->id}")->assertForbidden();
        $this->postJson('/api/crm/scraper/sites', ['platform_id' => $other->id, 'source_url' => 'https://x.test/'])->assertForbidden();
        $this->getJson('/api/crm/scraper/overview')->assertOk()->assertJsonCount(0, 'sites')->assertJsonPath('can_manage', false);
    }

    public function test_store_and_update_site_with_template_and_crawl_settings(): void
    {
        [$platform] = $this->actingManager();

        $site = $this->postJson('/api/crm/scraper/sites', [
            'platform_id' => $platform->id,
            'source_url' => 'https://escortwp.test/escorts/kenya/nairobi/',
            'template_key' => 'escortwp',
            'crawl_settings' => ['max_pages' => 999, 'delay_ms' => 1, 'cookies' => [['name' => 'age_ok', 'value' => '1'], ['name' => 'bad name', 'value' => 'x']]],
            'compliance_ack_robots' => true,
            'compliance_ack_tos' => true,
        ])->assertCreated()->json('site');

        $this->assertSame('escortwp.test', $site['name']);
        $this->assertSame(25, $site['crawl_settings']['max_pages']);
        $this->assertSame(1, $site['crawl_settings']['delay_ms']);
        $this->assertSame([['name' => 'age_ok', 'value' => '1']], $site['crawl_settings']['cookies']);

        $this->patchJson("/api/crm/scraper/sites/{$site['id']}", ['parser_rules' => ['phone_selector' => '.phone a'], 'source_url' => 'http://127.0.0.1/admin'])
            ->assertStatus(422);
        $this->patchJson("/api/crm/scraper/sites/{$site['id']}", ['parser_rules' => ['phone_selector' => '.phone a']])
            ->assertOk()->assertJsonPath('site.parser_rules.phone_selector', '.phone a');
    }

    // ------------------------------------------------------------------

    private function fakeEscortWpSite(): void
    {
        Http::fake([
            'https://escortwp.test/robots.txt' => Http::response("User-agent: *\nAllow: /\n", 200, ['content-type' => 'text/plain']),
            'https://escortwp.test/escorts/kenya/nairobi/page/2/' => Http::response($this->fixture('escortwp-page2.html'), 200, self::HTML),
            'https://escortwp.test/escorts/kenya/nairobi/page/3/' => Http::response($this->fixture('escortwp-page3.html'), 200, self::HTML),
            'https://escortwp.test/escorts/kenya/nairobi/' => Http::response($this->fixture('escortwp-page1.html'), 200, self::HTML),
        ]);
    }

    private function createSite(Platform $platform, User $user, array $overrides = []): ScraperSource
    {
        return ScraperSource::query()->create(array_merge([
            'platform_id' => $platform->id,
            'name' => 'EscortWP Nairobi',
            'source_url' => 'https://escortwp.test/escorts/kenya/nairobi/',
            'parser_profile' => 'contact_cards',
            'template_key' => 'escortwp',
            'fetch_driver' => 'http',
            'fetch_schedule' => 'manual_only',
            'dedupe_mode' => 'phone_or_email',
            'is_active' => true,
            'compliance_ack_robots' => true,
            'compliance_ack_tos' => true,
            'created_by' => $user->id,
        ], $overrides));
    }

    private function actingManager(): array
    {
        $platform = $this->createPlatform('Kenya');
        $user = $this->createUser('sub_admin', [$platform->id]);
        Sanctum::actingAs($user);

        return [$platform, $user];
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/scraper/' . $name));
    }

    private function createUser(string $role, array $marketIds): User
    {
        return User::query()->create([
            'name' => ucfirst($role) . ' ' . Str::random(5),
            'email' => Str::random(8) . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => $marketIds,
        ]);
    }

    private function createPlatform(string $name): Platform
    {
        return Platform::query()->create([
            'name' => $name,
            'domain' => Str::slug($name) . '-' . Str::random(6) . '.test',
            'country' => $name,
            'currency_code' => 'KES',
            'is_active' => true,
            'phone_prefix' => '254',
            'wp_api_url' => 'https://example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }
}
