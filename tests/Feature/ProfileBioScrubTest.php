<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Platform;
use App\Services\ProfileBioScrubService;
use App\Services\WpSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileBioScrubTest extends TestCase
{
    use RefreshDatabase;

    private const DIRTY_BIO = '<p>I keep things simple. Reach me on WhatsApp at +254794305988. I am 160cm and 60kg.</p>';

    public function test_scrubs_an_expired_profile_and_keeps_the_original(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createRestrictedClient($platform, 5001);
        $this->fakeWp($platform, 5001, self::DIRTY_BIO);

        $row = app(ProfileBioScrubService::class)->scrub($client);

        $this->assertSame('scrubbed', $row['action']);
        $this->assertSame(1, $row['redactions']);

        // The redacted bio was pushed to WordPress...
        Http::assertSent(function ($request) use ($platform) {
            $base = rtrim((string) $platform->wp_api_url, '/');
            if ($request->url() !== "{$base}/clients/5001/update") {
                return false;
            }
            $content = (string) data_get($request->data(), 'fields.content');

            return ! str_contains($content, '254794305988')
                && str_contains($content, '[contact hidden]')
                && str_contains($content, '160cm and 60kg'); // legitimate numbers survive
        });

        // ...and the untouched original is retained for renewal.
        $fresh = $client->fresh();
        $this->assertSame(self::DIRTY_BIO, $fresh->bio_original_html);
        $this->assertNotNull($fresh->bio_scrubbed_at);
        $this->assertSame(1, $fresh->bio_redactions);
        $this->assertDatabaseHas('timeline_events', [
            'entity_id' => $client->id,
            'event_type' => 'profile_bio_scrubbed',
        ]);
    }

    public function test_a_clean_bio_costs_no_wordpress_write(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createRestrictedClient($platform, 5002);
        $this->fakeWp($platform, 5002, '<p>Good company, easy conversation. I am 22.</p>');

        $row = app(ProfileBioScrubService::class)->scrub($client);

        $this->assertSame('clean', $row['action']);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/update'));
        $this->assertNull($client->fresh()->bio_scrubbed_at);
    }

    public function test_rescrubbing_never_overwrites_the_stored_original(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createRestrictedClient($platform, 5003);

        // First pass captures the original.
        $this->fakeWp($platform, 5003, self::DIRTY_BIO);
        app(ProfileBioScrubService::class)->scrub($client);
        $this->assertSame(self::DIRTY_BIO, $client->fresh()->bio_original_html);

        // Second pass sees an already-redacted bio plus a newly added number.
        $this->fakeWp($platform, 5003, '<p>[contact hidden] call 0712 345 678</p>');
        app(ProfileBioScrubService::class)->scrub($client->fresh());

        $this->assertSame(
            self::DIRTY_BIO,
            $client->fresh()->bio_original_html,
            'the advertiser original must survive a second scrub'
        );
    }

    public function test_restore_puts_the_original_back_and_clears_the_stamps(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createRestrictedClient($platform, 5004);
        $client->forceFill([
            'bio_original_html' => self::DIRTY_BIO,
            'bio_scrubbed_at' => now()->subDay(),
            'bio_redactions' => 1,
        ])->save();
        $this->fakeWp($platform, 5004, '<p>[contact hidden]</p>');

        $row = app(ProfileBioScrubService::class)->restore($client);

        $this->assertSame('restored', $row['action']);
        Http::assertSent(function ($request) use ($platform) {
            $base = rtrim((string) $platform->wp_api_url, '/');

            return $request->url() === "{$base}/clients/5004/update"
                && str_contains((string) data_get($request->data(), 'fields.content'), '254794305988');
        });

        $fresh = $client->fresh();
        $this->assertNull($fresh->bio_original_html);
        $this->assertNull($fresh->bio_scrubbed_at);
        $this->assertDatabaseHas('timeline_events', [
            'entity_id' => $client->id,
            'event_type' => 'profile_bio_restored',
        ]);
    }

    public function test_active_profiles_and_non_lifecycle_markets_are_left_alone(): void
    {
        Http::fake();

        $platform = $this->createPlatform();
        $active = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 5005,
            'profile_status' => 'publish',
            'lifecycle_state' => 'active',
        ]);
        $this->assertSame('not_restricted', app(ProfileBioScrubService::class)->scrub($active)['action']);

        $legacyPlatform = $this->createPlatform(false);
        $expiredElsewhere = $this->createRestrictedClient($legacyPlatform, 5006);
        $this->assertSame('market_not_enabled', app(ProfileBioScrubService::class)->scrub($expiredElsewhere)['action']);

        Http::assertNothingSent();
    }

    public function test_any_bio_write_to_a_restricted_profile_is_redacted(): void
    {
        // The Optimizer / SEO generator / bulk bios all funnel through
        // updateClientProfile, so none of them can republish contact details.
        $platform = $this->createPlatform();
        $this->createRestrictedClient($platform, 5007);
        $base = rtrim((string) $platform->wp_api_url, '/');
        Http::fake(["{$base}/clients/5007/update" => Http::response(['ok' => true], 200)]);

        (new WpSyncService($platform))->updateClientProfile(5007, [
            'content' => '<p>Fresh AI bio — call me on 0712 345 678.</p>',
        ]);

        Http::assertSent(function ($request) {
            $content = (string) data_get($request->data(), 'fields.content');

            return ! str_contains($content, '0712 345 678') && str_contains($content, '[contact hidden]');
        });
    }

    public function test_backfill_batch_covers_profiles_that_expired_before_the_feature(): void
    {
        $platform = $this->createPlatform();
        $old = $this->createRestrictedClient($platform, 5008);
        $this->fakeWp($platform, 5008, self::DIRTY_BIO);

        $summary = app(ProfileBioScrubService::class)->runBatch($platform->id, 100, true);
        $this->assertSame(1, $summary['processed']);
        $this->assertSame(1, $summary['would_scrub']);
        $this->assertNull($old->fresh()->bio_scrubbed_at, 'dry run must not write');

        $summary = app(ProfileBioScrubService::class)->runBatch($platform->id, 100, false);
        $this->assertSame(1, $summary['scrubbed']);
        $this->assertNotNull($old->fresh()->bio_scrubbed_at);
    }

    private function createRestrictedClient(Platform $platform, int $wpPostId): Client
    {
        return Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => $wpPostId,
            'name' => 'Expired ' . $wpPostId,
            'profile_status' => 'publish',
            'lifecycle_state' => 'expired',
            'lifecycle_expired_at' => now()->subDays(3),
        ]);
    }

    private function fakeWp(Platform $platform, int $wpPostId, string $bio): void
    {
        $base = rtrim((string) $platform->wp_api_url, '/');

        Http::fake([
            "{$base}/clients/{$wpPostId}" => Http::response([
                'wp_post_id' => $wpPostId,
                'post_status' => 'publish',
                'crm_lifecycle_state' => 'expired',
                'post' => ['id' => $wpPostId, 'content' => $bio],
                'modified_at' => now()->toIso8601String(),
            ], 200),
            "{$base}/clients/{$wpPostId}/update" => Http::response(['ok' => true], 200),
        ]);
    }

    private function createPlatform(bool $lifecycleEnabled = true): Platform
    {
        return Platform::query()->create([
            'name' => 'Test Market',
            'domain' => 'tm-' . Str::random(6) . '.example.test',
            'country' => 'Tanzania',
            'timezone' => 'Africa/Dar_es_Salaam',
            'phone_prefix' => '255',
            'currency_code' => 'TZS',
            'is_active' => true,
            'lifecycle_policy_enabled' => $lifecycleEnabled,
            'wp_api_url' => 'https://tm.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }
}
