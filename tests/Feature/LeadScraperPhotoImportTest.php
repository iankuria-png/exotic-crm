<?php

namespace Tests\Feature;

use App\Jobs\ImportLeadProfilePhotoJob;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\ClientSyncService;
use App\Services\Scraper\Fetch\UrlSafety;
use App\Services\Scraper\LeadPhotoDownloader;
use App\Services\Scraper\ListingExtractor;
use App\Services\WpDirectProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Scraped leads carry the listing photo URL on their lead_scraped timeline event. Converted
 * profiles get that photo as their main image, only while the profile has no photos.
 */
class LeadScraperPhotoImportTest extends TestCase
{
    use RefreshDatabase;

    private const WP = 'https://wp-market.test/wp-json/exotic-crm-sync/v1';

    private const PHOTO = 'https://competitor.example/uploads/amina.jpg';

    private Platform $market;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->market = Platform::factory()->create([
            'phone_prefix' => '254', 'db_host' => 'fixture', 'db_name' => 'fixture', 'db_user' => 'fixture', 'db_pass' => 'fixture',
            'wp_api_url' => self::WP, 'wp_api_user' => 'crm', 'wp_api_password' => 'secret',
        ]);
        $this->agent = User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [$this->market->id]]);
        Sanctum::actingAs($this->agent);
        // Public resolution for every fixture host, so no test depends on real DNS.
        $this->app->instance(UrlSafety::class, new UrlSafety(fn () => ['93.184.216.34']));
    }

    public function test_review_seals_the_photo_choice_and_conversion_queues_the_import(): void
    {
        Queue::fake();
        Http::fake([self::WP.'/locations*' => Http::response(['locations' => []], 200)]);
        $withPhoto = $this->scrapedLead('Amina', '254711000222', self::PHOTO);
        $without = $this->scrapedLead('Zena', '254711000333', null);

        $review = $this->postJson('/api/crm/leads/bulk-convert/preview', ['lead_ids' => [$withPhoto->id, $without->id], 'photo' => true])
            ->assertOk()->assertJsonPath('photo', true)->json();
        $rows = collect($review['rows'])->keyBy('id');
        $this->assertTrue($rows[$withPhoto->id]['has_photo']);
        $this->assertFalse($rows[$without->id]['has_photo']);

        $this->fakeProvisioning();
        $created = $this->postJson('/api/crm/leads/'.$withPhoto->id.'/bulk-convert', ['review_token' => $rows[$withPhoto->id]['review_token']])
            ->assertOk()->assertJsonPath('status', 'created')->assertJsonPath('photo', 'queued')->json();
        Queue::assertPushed(ImportLeadProfilePhotoJob::class, fn ($job) => $job->clientId === $created['client_id'] && $job->leadId === $withPhoto->id);

        $this->postJson('/api/crm/leads/'.$without->id.'/bulk-convert', ['review_token' => $rows[$without->id]['review_token']])
            ->assertOk()->assertJsonPath('photo', 'none');
        Queue::assertPushed(ImportLeadProfilePhotoJob::class, 1);
    }

    public function test_conversion_without_the_photo_option_queues_nothing(): void
    {
        Queue::fake();
        Http::fake([self::WP.'/locations*' => Http::response(['locations' => []], 200)]);
        $lead = $this->scrapedLead('Amina', '254711000222', self::PHOTO);

        $review = $this->postJson('/api/crm/leads/bulk-convert/preview', ['lead_ids' => [$lead->id]])->assertOk()->assertJsonPath('photo', false)->json();
        $this->fakeProvisioning();
        $this->postJson('/api/crm/leads/'.$lead->id.'/bulk-convert', ['review_token' => $review['rows'][0]['review_token']])
            ->assertOk()->assertJsonMissingPath('photo');
        Queue::assertNotPushed(ImportLeadProfilePhotoJob::class);
    }

    public function test_job_uploads_the_listing_photo_as_main_image_with_referer(): void
    {
        [$lead, $client] = $this->convertedPair(self::PHOTO);
        Http::fake([
            self::WP.'/clients/801/media' => Http::sequence()
                ->push(['data' => []], 200)
                ->push(['id' => 9001, 'is_main' => true], 200),
            self::PHOTO => Http::response($this->jpeg(640, 800), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->runJob($client, $lead);

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::PHOTO
            && $r->hasHeader('Referer', 'https://competitor.example/profile/amina')
            && str_contains($r->header('Accept')[0] ?? '', 'image/'));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/clients/801/media'));
        $event = TimelineEvent::query()->where('entity_id', $client->id)->where('event_type', 'lead_photo_imported')->first();
        $this->assertNotNull($event);
        $this->assertSame(9001, $event->content['attachment_id']);
        $this->assertSame('competitor.example', $event->content['source_host']);
        $this->assertSame([640, 800], [$event->content['width'], $event->content['height']]);
    }

    public function test_job_uses_the_wordpress_original_instead_of_the_listing_thumbnail(): void
    {
        // Labiby (24 Nov): the listing showed only a 170×206 sized copy of the photo.
        $thumb = 'https://competitor.example/wp-content/uploads/1795/17953599558856-170x206.webp';
        $this->assertSame(
            ['https://competitor.example/wp-content/uploads/1795/17953599558856.webp', $thumb],
            LeadPhotoDownloader::candidates($thumb)
        );
        $this->assertSame([self::PHOTO], LeadPhotoDownloader::candidates(self::PHOTO));

        [$lead, $client] = $this->convertedPair($thumb);
        Http::fake([
            self::WP.'/clients/801/media' => Http::sequence()->push(['data' => []], 200)->push(['id' => 9002], 200),
            'https://competitor.example/wp-content/uploads/1795/17953599558856.webp' => Http::response($this->jpeg(800, 1000), 200),
            $thumb => Http::response($this->jpeg(170, 206), 200),
        ]);

        $this->runJob($client, $lead);

        Http::assertNotSent(fn (HttpRequest $r) => $r->url() === $thumb);
        $event = TimelineEvent::query()->where('entity_id', $client->id)->where('event_type', 'lead_photo_imported')->first();
        $this->assertSame([800, 1000, true], [$event->content['width'], $event->content['height'], $event->content['full_size']]);
        $this->assertNotNull($event->created_at);
    }

    public function test_job_falls_back_to_the_thumbnail_and_explains_when_it_is_too_small(): void
    {
        $thumb = 'https://competitor.example/wp-content/uploads/1795/lesly-170x206.webp';
        [$lead, $client] = $this->convertedPair($thumb);
        Http::fake([
            self::WP.'/clients/801/media' => Http::response(['data' => []], 200),
            'https://competitor.example/wp-content/uploads/1795/lesly.webp' => Http::response('', 404),
            $thumb => Http::response($this->jpeg(170, 206), 200),
        ]);

        $this->runJob($client, $lead);

        $event = TimelineEvent::query()->where('entity_id', $client->id)->where('event_type', 'lead_photo_failed')->first();
        $this->assertSame('too_small', $event->content['error_code']);
        $this->assertNotNull($event->created_at);
    }

    public function test_listing_extractor_prefers_the_largest_srcset_image(): void
    {
        $this->assertSame('b-640x800.jpg', ListingExtractor::largestSrcsetUrl('a-170x206.jpg 170w, b-640x800.jpg 640w, c-300x360.jpg 300w'));
        $this->assertSame('hd.jpg', ListingExtractor::largestSrcsetUrl('sd.jpg 1x, hd.jpg 2x'));
        $this->assertSame('one.jpg', ListingExtractor::largestSrcsetUrl('one.jpg'));
        $this->assertNull(ListingExtractor::largestSrcsetUrl(''));
    }

    public function test_job_never_adds_a_photo_to_a_profile_that_already_has_media(): void
    {
        [$lead, $client] = $this->convertedPair(self::PHOTO);
        Http::fake([self::WP.'/clients/801/media' => Http::response(['data' => [['id' => 5, 'url' => 'https://wp-market.test/a.jpg', 'is_main' => true]]], 200)]);

        $this->runJob($client, $lead);

        Http::assertNotSent(fn (HttpRequest $r) => $r->url() === self::PHOTO);
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST');
        $this->assertSame('skipped', $this->outcome($client));
    }

    public function test_job_skips_when_the_scraped_listing_had_no_photo(): void
    {
        [$lead, $client] = $this->convertedPair(null);
        Http::fake();

        $this->runJob($client, $lead);

        Http::assertNothingSent();
        $this->assertSame('skipped', $this->outcome($client));
    }

    public function test_job_rejects_tiny_images_non_images_and_private_addresses(): void
    {
        [$lead, $client] = $this->convertedPair('https://competitor.example/logo.png');
        Http::fake([
            self::WP.'/clients/801/media' => Http::response(['data' => []], 200),
            'https://competitor.example/logo.png' => Http::response($this->jpeg(64, 64), 200),
        ]);
        $this->runJob($client, $lead);
        $this->assertSame('failed', $this->outcome($client));
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST');

        $downloader = new LeadPhotoDownloader(new UrlSafety(fn () => ['93.184.216.34']));
        Http::fake(['https://competitor.example/page' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html'])]);
        $this->assertSame('not_image', $downloader->download('https://competitor.example/page', null)['error_code']);

        $private = new LeadPhotoDownloader(new UrlSafety(fn () => ['10.0.0.5']));
        $this->assertSame('unsafe_url', $private->download('https://competitor.example/a.jpg', null)['error_code']);

        // A redirect into a private network is stopped at the hop.
        $resolver = fn (string $host) => $host === 'internal.example' ? ['192.168.1.4'] : ['93.184.216.34'];
        Http::fake(['https://competitor.example/r.jpg' => Http::response('', 302, ['Location' => 'http://internal.example/x.jpg'])]);
        $this->assertSame('unsafe_url', (new LeadPhotoDownloader(new UrlSafety($resolver)))->download('https://competitor.example/r.jpg', null)['error_code']);
    }

    public function test_backfill_command_is_a_dry_run_unless_applied(): void
    {
        Queue::fake();
        [$lead, $client] = $this->convertedPair(self::PHOTO);
        [, $noPhoto] = $this->convertedPair(null, '254711000444', 802);

        $this->artisan('leads:import-scraped-photos', ['--platform' => $this->market->id])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();
        Queue::assertNothingPushed();

        $this->artisan('leads:import-scraped-photos', ['--platform' => $this->market->id, '--apply' => true])->assertSuccessful();
        Queue::assertPushed(ImportLeadProfilePhotoJob::class, fn ($job) => $job->clientId === $client->id && $job->leadId === $lead->id);
        Queue::assertNotPushed(ImportLeadProfilePhotoJob::class, fn ($job) => $job->clientId === $noPhoto->id);
    }

    private function scrapedLead(string $name, string $phone, ?string $photo): Lead
    {
        $lead = Lead::create(['platform_id' => $this->market->id, 'name' => $name, 'phone_normalized' => $phone, 'source' => 'import', 'status' => 'new',
            'source_url' => 'https://competitor.example/profile/'.strtolower($name)]);
        TimelineEvent::query()->create([
            'platform_id' => $this->market->id, 'entity_type' => 'lead', 'entity_id' => $lead->id, 'event_type' => 'lead_scraped',
            'actor_id' => $this->agent->id, 'content' => array_filter(['source_url' => $lead->source_url, 'image_url' => $photo]), 'created_at' => now(),
        ]);

        return $lead;
    }

    /** @return array{0: Lead, 1: Client} */
    private function convertedPair(?string $photo, string $phone = '254711000222', int $postId = 801): array
    {
        $lead = $this->scrapedLead('Amina', $phone, $photo);
        $client = Client::factory()->create(['platform_id' => $this->market->id, 'wp_post_id' => $postId, 'name' => 'Amina', 'main_image_url' => null, 'display_image_url' => null]);
        $lead->forceFill(['status' => 'converted', 'converted_client_id' => $client->id])->save();

        return [$lead, $client];
    }

    private function runJob(Client $client, Lead $lead): void
    {
        (new ImportLeadProfilePhotoJob($client->id, $lead->id, $this->agent->id))->handle(app(LeadPhotoDownloader::class));
    }

    private function outcome(Client $client): ?string
    {
        $type = TimelineEvent::query()->where('entity_id', $client->id)->where('event_type', 'like', 'lead_photo_%')->latest('id')->value('event_type');

        return $type ? substr($type, strlen('lead_photo_')) : null;
    }

    private function fakeProvisioning(): void
    {
        $provisioning = \Mockery::mock(WpDirectProvisioningService::class);
        $provisioning->shouldReceive('provisionEscort')->andReturnUsing(fn () => ['wp_post_id' => random_int(500, 9000), 'wp_user_id' => random_int(500, 9000), 'wp_post_status' => 'private']);
        $this->app->bind(WpDirectProvisioningService::class, fn () => $provisioning);
        $sync = \Mockery::mock(ClientSyncService::class);
        $sync->shouldReceive('syncOne')->andThrow(new \RuntimeException('Fixture: no external sync'));
        $this->app->bind(ClientSyncService::class, fn () => $sync);
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 140));
        ob_start();
        imagejpeg($image, null, 80);

        return (string) ob_get_clean();
    }
}
