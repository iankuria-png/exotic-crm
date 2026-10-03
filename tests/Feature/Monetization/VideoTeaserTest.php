<?php

namespace Tests\Feature\Monetization;

use App\Http\Controllers\Wp\PremiumContentController;
use App\Jobs\ProcessMonetizationAutomationItem;
use App\Models\Client;
use App\Models\ContentMonetizationSetting;
use App\Models\MonetizationAutomationItem;
use App\Models\Platform;
use App\Models\PremiumContentAsset;
use App\Services\BillingModeService;
use App\Services\Monetization\ExpiryAutomationService;
use App\Services\Monetization\OfferService;
use App\Services\Monetization\SyncService;
use App\Services\Monetization\TeaserService;
use App\Services\MonetizationSettingsService;
use App\Services\WalletSyncService;
use App\Support\ClientLifecycleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class VideoTeaserTest extends TestCase
{
    use RefreshDatabase;

    private Platform $market;

    private ContentMonetizationSetting $settings;

    private array $wpCalls = [];

    /** @var array<string, array> Scripted WordPress replies keyed by asset public id. */
    private array $wpTeaser = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        $this->mock(WalletSyncService::class, fn ($m) => $m->shouldReceive('syncClientBalanceById')->andReturn(['status' => 'skipped']));
        $this->mock(BillingModeService::class, function ($m) {
            $m->shouldReceive('assertWalletAvailable')->andReturn(['environment' => 'production']);
            $m->shouldReceive('providerContext')->andReturn([]);
        });
        $this->partialMock(SyncService::class, function ($m) {
            $m->shouldReceive('send')->andReturnUsing(function ($s, $route, $payload) {
                $this->wpCalls[] = [$route, $payload];
                if ($route === '/premium-content/teaser') {
                    return $this->wpTeaser[$payload['public_id']] ?? ['status' => 'synced', 'response' => ['teaser_url' => 'https://example.test/uploads/private-previews/'.$payload['public_id'].'-teaser-'.$payload['strength'].'.mp4', 'teaser_strength' => $payload['strength']]];
                }

                return ['status' => 'synced', 'response' => []];
            });
            $m->shouldReceive('profile')->andReturn(['status' => 'synced']);
        });
        $this->market = Platform::factory()->create(['currency_code' => 'KES', 'phone_prefix' => '254']);
        $this->settings = app(MonetizationSettingsService::class)->forPlatform($this->market);
        $this->settings->update(['enabled' => true, 'rollout_mode' => 'live', 'heartbeat_at' => now(), 'readiness_json' => ['ready' => true, 'checks' => ['video_processing' => true]]]);
        app(MonetizationSettingsService::class)->system()->update(['enabled' => true]);
    }

    private function creator(string $name): Client
    {
        return Client::factory()->create(['platform_id' => $this->market->id, 'name' => $name, 'client_type' => 'escort', 'wp_post_id' => random_int(1000, 999999), 'wp_user_id' => random_int(1000, 999999), 'profile_status' => 'publish', 'lifecycle_state' => ClientLifecycleState::EXPIRED, 'lifecycle_expired_at' => now()->subDay(), 'lifecycle_archived_at' => null, 'closed_at' => null, 'escort_expire' => now()->subDay()->timestamp, 'is_high_risk' => false, 'wallet_currency' => 'KES']);
    }

    private function asset(Client $client, string $type = 'video', array $extra = []): PremiumContentAsset
    {
        return PremiumContentAsset::create($extra + ['public_id' => (string) Str::uuid(), 'platform_id' => $this->market->id, 'client_id' => $client->id, 'wp_post_id' => $client->wp_post_id, 'wp_attachment_id' => random_int(100, 999999), 'media_type' => $type, 'preview_url' => 'https://example.test/blur.jpg', 'duration_seconds' => 60, 'content_fingerprint' => str_repeat('a', 64), 'origin' => 'creator']);
    }

    private function savePolicy(array $teaser): void
    {
        $s = $this->settings->fresh('prices');
        app(MonetizationSettingsService::class)->save($this->market, ['reason' => 'Card preview settings', 'config_revision' => $s->config_revision, 'enabled' => true, 'rollout_mode' => 'live', 'activation_kill_switch' => false, 'checkout_kill_switch' => false,
            'prices' => [['duration_key' => '1_month', 'price' => 500, 'subsidy_mode' => 'fixed', 'subsidy_value' => 100, 'is_active' => true]],
            'offer_policy' => $s->offer_policy_json, 'surface_policy' => $s->surface_policy_json, 'checkout_policy' => $s->checkout_policy_json, 'delivery_policy' => $s->delivery_policy_json, 'teaser_policy' => $teaser], 1);
    }

    private function process(): void
    {
        Queue::assertPushed(ProcessMonetizationAutomationItem::class, function ($job) {
            (new ProcessMonetizationAutomationItem($job->itemId))->handle();

            return true;
        });
    }

    public function test_policy_defaults_validation_and_runtime_exposure(): void
    {
        $settings = app(MonetizationSettingsService::class);
        $this->assertSame(['enabled' => true, 'strength' => 'shapes'], $settings->teaserPolicy($this->settings));
        $this->assertSame(['enabled' => true, 'strength' => 'shapes'], $settings->runtime($this->settings)['teaser_policy']);

        $this->savePolicy(['enabled' => false, 'strength' => 'colours']);
        $this->assertSame(['enabled' => false, 'strength' => 'colours'], $settings->runtime($this->settings->fresh())['teaser_policy']);

        $this->expectException(ValidationException::class);
        $this->savePolicy(['enabled' => true, 'strength' => 'sharp']);
    }

    public function test_backfill_generates_missing_and_stale_previews_and_skips_the_rest(): void
    {
        $beki = $this->creator('Beki');
        $missing = $this->asset($beki);
        $stale = $this->asset($beki, 'video', ['teaser_url' => 'https://example.test/old.mp4', 'teaser_strength' => 'outlines']);
        $ready = $this->asset($beki, 'video', ['teaser_url' => 'https://example.test/ok.mp4', 'teaser_strength' => 'shapes']);
        $this->asset($beki, 'photo');
        $this->asset($beki, 'video', ['status' => 'held']);
        $this->asset($beki, 'video', ['status' => 'deleted']);
        $gone = $this->asset($beki);
        $this->wpTeaser[$gone->public_id] = ['status' => 'failed', 'message' => 'WordPress rejected sync (410).'];

        $service = app(TeaserService::class);
        $this->assertSame(['teaser_videos' => 4, 'teaser_ready' => 1, 'teaser_remaining' => 3], $service->estimates($this->market));
        $run = $service->startBackfill($this->market, 1);
        $this->assertSame(3, $run->total_count);
        $this->assertEqualsCanonicalizing([$missing->id, $stale->id, $gone->id], MonetizationAutomationItem::where('run_id', $run->id)->pluck('asset_id')->all());
        // Videos waiting in a running batch are never picked twice.
        $this->assertSame(0, $service->remaining($this->market)->count());

        $this->process();
        $run->refresh();
        $this->assertSame([2, 1, 0, 'completed'], [$run->succeeded_count, $run->skipped_count, $run->failed_count, $run->status]);
        $this->assertStringEndsWith('-teaser-shapes.mp4', $missing->fresh()->teaser_url);
        $this->assertSame('shapes', $stale->fresh()->teaser_strength);
        $this->assertNotNull($stale->fresh()->teaser_generated_at);
        $this->assertSame('https://example.test/ok.mp4', $ready->fresh()->teaser_url);
        $this->assertSame('file_missing', MonetizationAutomationItem::where('asset_id', $gone->id)->value('result_code'));
        $this->assertSame(['public_id' => $missing->public_id, 'strength' => 'shapes'], collect($this->wpCalls)->firstWhere(0, '/premium-content/teaser')[1]);

        // A video whose protected file is gone stays visible as remaining rather than silently ready.
        $this->assertSame([$gone->id], $service->remaining($this->market)->pluck('id')->all());
    }

    public function test_outdated_wordpress_fails_for_retry_and_disabled_previews_cannot_start(): void
    {
        $asset = $this->asset($this->creator('Wanjiru'));
        $this->wpTeaser[$asset->public_id] = ['status' => 'failed', 'message' => 'WordPress rejected sync (404).'];
        $run = app(TeaserService::class)->startBackfill($this->market, 1);
        $this->process();
        $this->assertSame([1, 'completed_with_errors'], [$run->fresh()->failed_count, $run->fresh()->status]);
        $this->assertSame('wordpress_outdated', MonetizationAutomationItem::where('run_id', $run->id)->value('result_code'));
        $this->assertNull($asset->fresh()->teaser_url);

        $this->savePolicy(['enabled' => false, 'strength' => 'shapes']);
        $this->expectException(HttpException::class);
        app(TeaserService::class)->startBackfill($this->market, 1);
    }

    public function test_expiry_conversion_keeps_the_teaser_and_offers_show_it_only_while_enabled(): void
    {
        $beki = $this->creator('Beki');
        $rule = app(MonetizationSettingsService::class)->expiryPolicy($this->settings);
        $published = app(ExpiryAutomationService::class)->publish($this->settings, $beki, $rule, ['wp_attachment_id' => 77, 'public_id' => (string) Str::uuid(), 'media_type' => 'video', 'duration_seconds' => 40, 'preview_url' => 'https://example.test/blur.jpg', 'teaser_url' => 'https://example.test/t-shapes.mp4', 'teaser_strength' => 'shapes', 'content_fingerprint' => str_repeat('b', 64)]);
        $asset = PremiumContentAsset::where('wp_attachment_id', 77)->firstOrFail();
        $this->assertSame(['https://example.test/t-shapes.mp4', 'shapes'], [$asset->teaser_url, $asset->teaser_strength]);
        $this->assertSame('https://example.test/t-shapes.mp4', app(OfferService::class)->present($published['offer']->fresh('assets'))['assets'][0]['teaser_url']);

        $this->savePolicy(['enabled' => false, 'strength' => 'shapes']);
        $this->assertNull(app()->make(OfferService::class)->present($published['offer']->fresh('assets'))['assets'][0]['teaser_url']);
    }

    public function test_homepage_cards_get_one_batched_summary_with_the_best_preview(): void
    {
        $beki = $this->creator('Beki');
        $wanjiru = $this->creator('Wanjiru');
        $service = app(ExpiryAutomationService::class);
        $rule = array_replace_recursive(app(MonetizationSettingsService::class)->expiryPolicy($this->settings), ['media_types' => ['video', 'photo'], 'pricing' => ['photo_amount' => 300]]);
        $convert = fn (Client $c, int $id, string $type, ?string $teaser) => $service->publish($this->settings, $c, $rule, ['wp_attachment_id' => $id, 'public_id' => (string) Str::uuid(), 'media_type' => $type, 'duration_seconds' => $type === 'video' ? 40 : null, 'preview_url' => "https://example.test/$id.jpg", 'teaser_url' => $teaser, 'teaser_strength' => $teaser ? 'shapes' : null, 'content_fingerprint' => str_repeat('c', 64)]);
        $convert($beki, 1, 'video', 'https://example.test/1-teaser.mp4');
        $convert($beki, 2, 'video', null);
        $convert($beki, 3, 'photo', null);
        $convert($wanjiru, 4, 'photo', null);

        $request = Request::create('/catalog', 'POST', ['surface' => 'profiles', 'wp_post_ids' => [$beki->wp_post_id, $wanjiru->wp_post_id, 123]]);
        $request->attributes->set('wallet_platform', $this->market);
        $data = app(PremiumContentController::class)->catalog($request)->getData(true);
        $profiles = collect($data['profiles'])->keyBy('wp_post_id');

        $this->assertCount(2, $profiles);
        $this->assertSame([2, 1, 0, 3], [$profiles[$beki->wp_post_id]['videos'], $profiles[$beki->wp_post_id]['photos'], $profiles[$beki->wp_post_id]['bundles'], $profiles[$beki->wp_post_id]['item_count']]);
        // Her newest video with a teaser wins over a newer video without one.
        $this->assertSame('https://example.test/1-teaser.mp4', $profiles[$beki->wp_post_id]['preview']['teaser_url']);
        $this->assertSame(['photo', null], [$profiles[$wanjiru->wp_post_id]['preview']['media_type'], $profiles[$wanjiru->wp_post_id]['preview']['teaser_url']]);
        $this->assertSame([], $data['offers']);
    }
}
