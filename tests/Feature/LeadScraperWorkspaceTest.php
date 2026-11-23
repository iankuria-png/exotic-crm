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

    public function test_city_text_rule_reads_place_from_card_description(): void
    {
        [$platform] = $this->actingManager();
        Http::fake([
            'https://wp-dir.test/robots.txt' => Http::response('', 404),
            'https://wp-dir.test/' => Http::response($this->fixture('wp-profile-preview.html'), 200, self::HTML),
        ]);
        $rules = [
            'row_selector' => 'div.profile-preview', 'name_selector' => 'h5 a', 'phone_selector' => 'a[href^="tel:"]',
            'link_selector' => 'h5 a', 'age_selector' => '.profile-text p', 'city_selector' => '.profile-text p',
        ];

        $marker = $this->postJson('/api/crm/scraper/probe', ['url' => 'https://wp-dir.test/', 'platform_id' => $platform->id, 'parser_rules' => $rules + ['city_text' => 'escort from']])
            ->assertOk()->json('profile.sample');
        $this->assertSame(['Westlands Nairobi', 'South B Nairobi in Sana Sana', 'Kisumu Kisumu in Kisumu Town'], array_column($marker, 'city'));
        $this->assertSame([21, 22, 23], array_column($marker, 'age'));
        $this->assertSame('Girl 1', $marker[0]['name']);

        $regex = $this->postJson('/api/crm/scraper/probe', ['url' => 'https://wp-dir.test/', 'platform_id' => $platform->id, 'parser_rules' => $rules + ['city_text' => '/from (.+?)(?: in .*)?$/u']])
            ->assertOk()->json('profile.sample');
        $this->assertSame(['Westlands Nairobi', 'South B Nairobi', 'Kisumu Kisumu'], array_column($regex, 'city'));

        // Without a rule, a long description is still trimmed to the place after "from".
        $auto = $this->postJson('/api/crm/scraper/probe', ['url' => 'https://wp-dir.test/', 'platform_id' => $platform->id, 'parser_rules' => $rules])
            ->assertOk()->json('profile.sample');
        $this->assertSame('South B Nairobi in Sana Sana', $auto[1]['city']);
    }

    public function test_location_filter_limits_runs_and_can_be_saved_as_site_default(): void
    {
        [$platform, $user] = $this->actingManager();
        Http::fake([
            'https://wp-dir.test/robots.txt' => Http::response('', 404),
            'https://wp-dir.test/' => Http::response($this->fixture('wp-profile-preview.html'), 200, self::HTML),
        ]);
        $site = $this->createSite($platform, $user, [
            'source_url' => 'https://wp-dir.test/',
            'template_key' => null,
            'parser_rules' => ['row_selector' => 'div.profile-preview', 'name_selector' => 'h5 a', 'link_selector' => 'h5 a', 'city_selector' => '.profile-text p', 'city_text' => 'escort from'],
            'crawl_settings' => ['max_pages' => 1, 'delay_ms' => 0],
        ]);

        // Probe offers places with counts.
        $facets = $this->postJson("/api/crm/scraper/sites/{$site->id}/probe")->assertOk()->json('profile.locations');
        $labels = array_column($facets, 'label');
        $this->assertContains('Nairobi', $labels);
        $this->assertContains('South B', $labels);
        $this->assertContains('Kisumu', $labels);

        // Only Westlands + Kisumu, case-insensitive, and saved as the default.
        $run = $this->postJson("/api/crm/scraper/sites/{$site->id}/runs", ['mode' => 'dry_run', 'locations' => ['westlands', 'KISUMU'], 'save_as_default' => true])
            ->assertStatus(202)->json('run');
        $run = ScraperRun::query()->findOrFail($run['id']);
        $this->assertSame(['Girl 1', 'Girl 3'], array_column($run->preview, 'name'));
        $this->assertSame(1, $run->result['outside_location']);
        $this->assertSame(['westlands', 'KISUMU'], $run->filters['locations']);
        $this->assertSame(['westlands', 'KISUMU'], $site->fresh()->crawl_settings['locations']);

        // A later run without a choice uses the site default; import creates only matching leads.
        $this->postJson("/api/crm/scraper/sites/{$site->id}/runs", ['mode' => 'import'])->assertStatus(202);
        $this->assertSame(['Girl 1', 'Girl 3'], Lead::query()->orderBy('name')->pluck('name')->all());

        // An explicit empty list means "everywhere" for that run.
        $all = $this->postJson("/api/crm/scraper/sites/{$site->id}/runs", ['mode' => 'dry_run', 'locations' => []])->assertStatus(202)->json('run');
        $this->assertCount(3, ScraperRun::query()->findOrFail($all['id'])->preview);
    }

    public function test_location_filter_reports_when_nothing_matches_and_sales_cannot_save_default(): void
    {
        [$platform, $user] = $this->actingManager();
        $this->fakeEscortWpSite();
        $site = $this->createSite($platform, $user, ['crawl_settings' => ['max_pages' => 1, 'delay_ms' => 0]]);

        $run = $this->postJson("/api/crm/scraper/sites/{$site->id}/runs", ['mode' => 'dry_run', 'locations' => ['Mombasa']])->assertStatus(202)->json('run');
        $run = ScraperRun::query()->findOrFail($run['id']);
        $this->assertSame('empty', $run->status);
        $this->assertSame(6, $run->result['outside_location']);
        $this->assertContains('Westlands', array_column($run->result['locations'], 'label'));

        $sales = $this->createUser('sales', [$platform->id]);
        Sanctum::actingAs($sales);
        $this->postJson("/api/crm/scraper/sites/{$site->id}/runs", ['mode' => 'dry_run', 'locations' => ['Nairobi'], 'save_as_default' => true])->assertForbidden();
        $this->assertArrayNotHasKey('locations', (array) $site->fresh()->crawl_settings);
    }

    public function test_age_gate_es10_template_clean_text_and_phone_sanity(): void
    {
        [$platform] = $this->actingManager();
        $es10Card = fn (int $i, string $extra = '') => '<div ppt-box class="list-info-pop-wrap hide-mobile"><figure><a href="https://es10.test/listing/girl-'.$i.'/"><div class="text-white fs-4 text-600">Girl '.$i.' <span class="text-online">•</span></div><div class="fs-sm text-white">Addis Ababa</div><div class="list-info-pop"><ul><li><span>Age</span> 2'.$i.'</li></ul></div></a></figure>'.$extra.'</div>';
        Http::fake([
            'https://gate.test/robots.txt' => Http::response('', 404),
            'https://gate.test/accueil' => Http::response('', 302, ['location' => 'https://gate.test/verification']),
            'https://gate.test/verification' => Http::response('<html><body><form method="post"><select name="dob_year"></select><button name="isAdult" value="yes">Enter</button></form></body></html>', 200, self::HTML),
            'https://es10.test/robots.txt' => Http::response('', 404),
            'https://es10.test/' => Http::response('<html><head><link rel="stylesheet" href="https://es10.test/wp-content/themes/ES10/style.css"></head><body>'
                .$es10Card(1, '<p>Listed 1792233107844</p>').$es10Card(2, '<p>Call 0912 345 678</p>').$es10Card(3).'</body></html>', 200, self::HTML),
            'https://svg.test/robots.txt' => Http::response('', 404),
            'https://svg.test/' => Http::response('<html><body>'.implode('', array_map(fn ($i) => '<article class="ad"><a href="/p/'.$i.'"><span class="ttl">Name '.$i.'</span><svg><style>.st0{fill:#fff;}</style></svg></a><a href="tel:+25191000000'.$i.'">Call</a></article>', [1, 2, 3])).'</body></html>', 200, self::HTML),
        ]);

        $gate = $this->postJson('/api/crm/scraper/probe', ['url' => 'https://gate.test/accueil', 'platform_id' => $platform->id])->assertOk()->json('profile');
        $this->assertSame('blocked', $gate['verdict']);
        $this->assertSame(['age_gate'], array_column($gate['blockers'], 'key'));

        $es10 = $this->postJson('/api/crm/scraper/probe', ['url' => 'https://es10.test/', 'platform_id' => $platform->id])->assertOk()->json('profile');
        $this->assertSame('premiumpress_es10', $es10['template']['key']);
        $this->assertSame(['Girl 1', 'Girl 2', 'Girl 3'], array_column($es10['sample'], 'name'));
        $this->assertSame([21, 22, 23], array_column($es10['sample'], 'age'));
        $this->assertNull($es10['sample'][0]['phone'], 'A 13-digit id in text is not a phone.');
        $this->assertSame('254912345678', $es10['sample'][1]['phone'], 'A local 0-prefixed number in text is.');

        $svg = $this->postJson('/api/crm/scraper/probe', ['url' => 'https://svg.test/', 'platform_id' => $platform->id])->assertOk()->json('profile');
        $this->assertSame('Name 1', $svg['sample'][0]['name']);
        $this->assertStringNotContainsString('fill', (string) $svg['sample'][0]['name']);
    }

    public function test_probe_treats_hidden_phones_as_expected_when_profiles_are_opened(): void
    {
        [$platform] = $this->actingManager();
        Http::fake([
            'https://escortwp.test/robots.txt' => Http::response('', 404),
            'https://escortwp.test/escorts/kenya/nairobi/' => Http::response($this->fixture('escortwp-nophones.html'), 200, self::HTML),
        ]);
        $url = 'https://escortwp.test/escorts/kenya/nairobi/';

        $plain = $this->postJson('/api/crm/scraper/probe', ['url' => $url, 'platform_id' => $platform->id])->assertOk()->json('profile');
        $this->assertSame('needs_config', $plain['verdict']);
        $this->assertSame('phones_hidden', $plain['blockers'][0]['key']);

        $following = $this->postJson('/api/crm/scraper/probe', ['url' => $url, 'platform_id' => $platform->id, 'crawl_settings' => ['follow_profiles' => true, 'max_profiles' => 40]])->assertOk()->json('profile');
        $this->assertSame('ready', $following['verdict']);
        $this->assertSame('phones_on_profiles', $following['blockers'][0]['key']);
        $this->assertSame('info', $following['blockers'][0]['severity']);
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
        return (string) file_get_contents(base_path('tests/Fixtures/scraper/'.$name));
    }

    private function createUser(string $role, array $marketIds): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' '.Str::random(5),
            'email' => Str::random(8).'@example.test',
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
            'domain' => Str::slug($name).'-'.Str::random(6).'.test',
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
