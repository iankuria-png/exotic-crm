<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\Product;
use App\Support\SubscriptionExpiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackfillVvipPlacementsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const BEFORE = [
        'premium' => true,
        'featured' => true,
        'campaign_id' => 0,
        'status' => '',
        'start_date' => '',
        'end_date' => '',
        'is_vvip' => false,
    ];

    private ?Product $product = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Production MySQL accepts plan_type 'vvip' (2026_05_14 migration is
        // MySQL-only); the SQLite test schema still carries the old CHECK.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = ON');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/vvip-placement-backfill'));

        parent::tearDown();
    }

    public function test_dry_run_only_asks_wordpress_and_selects_escorts_with_an_active_vvip_deal(): void
    {
        $platform = $this->createPlatform();
        $vvip = $this->clientWithDeal($platform, 'vvip', 'escort', 71001);
        $this->clientWithDeal($platform, 'vvip', 'agency', 71002);
        $this->clientWithDeal($platform, 'vip', 'escort', 71003);
        $this->clientWithDeal($platform, 'vvip', 'escort', 71004, now()->subDay());
        $this->fakeWordPress($platform);

        $this->artisan('crm:backfill-vvip-placements')
            ->expectsOutputToContain('(DRY-RUN): 1 escort profile(s)')
            ->expectsOutputToContain('would_create_campaign')
            ->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), "/clients/{$vvip->wp_post_id}/vvip-placement")
            && $request['dry_run'] === true);
        $this->assertFalse(File::isDirectory(storage_path('app/vvip-placement-backfill')));
    }

    public function test_apply_backs_up_first_then_places_with_the_market_end_of_day_expiry_and_verifies(): void
    {
        $platform = $this->createPlatform();
        $client = $this->clientWithDeal($platform, 'vvip', 'escort', 72001);
        $deal = $client->deals()->first();
        $this->fakeWordPress($platform);

        $this->artisan('crm:backfill-vvip-placements', ['--apply' => true])
            ->expectsOutputToContain('LIVE')
            ->expectsOutputToContain('Backup written')
            ->expectsOutputToContain('create_campaign')
            ->expectsOutputToContain('Applied and verified 1 of 1')
            ->assertExitCode(0);

        $expectedExpiry = SubscriptionExpiry::endOfDay($deal->expires_at->timestamp, 'Africa/Kampala');
        Http::assertSent(fn (ClientRequest $request): bool => $request['dry_run'] === false
            && (int) $request['expires_at'] === $expectedExpiry
            && (int) $request['crm_deal_id'] === (int) $deal->id);

        $files = File::files(storage_path('app/vvip-placement-backfill'));
        $this->assertCount(1, $files);
        $backup = json_decode(File::get($files[0]->getPathname()), true);
        $this->assertSame(self::BEFORE, $backup['rows'][0]['before']);
        $this->assertTrue($backup['results'][0]['verified']);
    }

    public function test_revert_sends_the_recorded_state_back_and_is_a_dry_run_without_apply(): void
    {
        $platform = $this->createPlatform();
        $this->clientWithDeal($platform, 'vvip', 'escort', 73001);
        $this->fakeWordPress($platform);

        $this->artisan('crm:backfill-vvip-placements', ['--apply' => true])->assertExitCode(0);
        $backupPath = File::files(storage_path('app/vvip-placement-backfill'))[0]->getPathname();

        $this->artisan('crm:backfill-vvip-placements', ['--revert' => $backupPath])
            ->expectsOutputToContain('would_revert')
            ->assertExitCode(0);
        $this->artisan('crm:backfill-vvip-placements', ['--revert' => $backupPath, '--apply' => true])
            ->expectsOutputToContain('reverted')
            ->assertExitCode(0);

        Http::assertSent(fn (ClientRequest $request): bool => ($request->data()['mode'] ?? null) === 'revert'
            && $request['dry_run'] === false
            && $request['restore'] === self::BEFORE);
    }

    private function fakeWordPress(Platform $platform): void
    {
        $baseUrl = rtrim($platform->wp_api_url, '/');

        Http::fake([
            "{$baseUrl}/clients/*/vvip-placement" => function (ClientRequest $request) {
                if (($request['mode'] ?? '') === 'revert') {
                    return Http::response(['action' => $request['dry_run'] ? 'would_revert' : 'reverted', 'before' => []], 200);
                }

                if ($request['dry_run']) {
                    return Http::response(['action' => 'would_create_campaign', 'before' => self::BEFORE], 200);
                }

                return Http::response([
                    'action' => 'create_campaign',
                    'before' => self::BEFORE,
                    'after' => array_merge(self::BEFORE, ['campaign_id' => 501, 'status' => 'active', 'is_vvip' => true]),
                ], 200);
            },
        ]);
    }

    private function clientWithDeal(Platform $platform, string $plan, string $type, int $wpPostId, $expiresAt = null): Client
    {
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => $wpPostId,
            'client_type' => $type,
            'profile_status' => 'publish',
            'premium' => true,
            'featured' => true,
        ]);

        // One product for every deal: the product factory's unique names run out.
        $this->product ??= Product::factory()->create();

        Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $this->product->id,
            'plan_type' => $plan,
            'status' => 'active',
            'activated_at' => now()->subDays(3),
            'expires_at' => $expiresAt ?? now()->addDays(20),
        ]);

        return $client;
    }

    private function createPlatform(): Platform
    {
        return Platform::query()->create([
            'name' => 'Uganda Market',
            'domain' => 'ug-'.Str::random(6).'.example.test',
            'country' => 'Uganda',
            'timezone' => 'Africa/Kampala',
            'phone_prefix' => '256',
            'currency_code' => 'UGX',
            'is_active' => true,
            'lifecycle_policy_enabled' => true,
            'wp_api_url' => 'https://ug.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }
}
