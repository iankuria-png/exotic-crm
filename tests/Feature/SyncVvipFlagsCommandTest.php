<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncVvipFlagsCommandTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_dry_run_covers_every_market_and_only_asks_for_the_flag(): void
    {
        $kenya = $this->createPlatform('Kenya', 'ke');
        $uganda = $this->createPlatform('Uganda', 'ug');
        $this->clientWithVvipDeal($kenya, 81001);
        $this->clientWithVvipDeal($uganda, 81002);
        $this->fakeMarket($kenya, true);
        $this->fakeMarket($uganda, true);

        $this->artisan('crm:sync-vvip-flags')
            ->expectsOutputToContain('(DRY-RUN): 2 escort profile(s)')
            ->expectsOutputToContain('would_set_flag=2')
            ->assertExitCode(0);

        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request): bool => $request['flag_only'] === true && $request['dry_run'] === true);
    }

    public function test_apply_never_writes_to_a_market_that_does_not_confirm_flag_only(): void
    {
        $kenya = $this->createPlatform('Kenya', 'ke');
        $legacy = $this->createPlatform('Legacy', 'lg');
        $this->clientWithVvipDeal($kenya, 82001);
        $this->clientWithVvipDeal($legacy, 82002);
        $this->fakeMarket($kenya, true);
        $this->fakeMarket($legacy, false);

        $this->artisan('crm:sync-vvip-flags', ['--apply' => true])
            ->expectsOutputToContain('needs exotic-crm-sync 1.3.20')
            ->expectsOutputToContain('Applied and verified 1 of 1')
            ->assertExitCode(0);

        $legacyBase = rtrim($legacy->wp_api_url, '/');
        Http::assertNotSent(fn (ClientRequest $request): bool => str_starts_with($request->url(), $legacyBase)
            && $request['dry_run'] === false);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/clients/82001/vvip-placement')
            && $request['dry_run'] === false
            && $request['flag_only'] === true);
    }

    /**
     * A 1.3.20 market echoes flag_only and sets the flag; a 1.3.19 market
     * ignores the parameter and plans a campaign.
     */
    private function fakeMarket(Platform $platform, bool $supportsFlagOnly): void
    {
        $baseUrl = rtrim($platform->wp_api_url, '/');
        $before = ['premium' => true, 'featured' => true, 'vvip_flag' => false, 'is_vvip' => false];

        Http::fake([
            "{$baseUrl}/clients/*/vvip-placement" => function (ClientRequest $request) use ($supportsFlagOnly, $before) {
                if (! $supportsFlagOnly) {
                    return Http::response(['action' => $request['dry_run'] ? 'would_create_campaign' : 'create_campaign', 'before' => $before], 200);
                }

                if ($request['dry_run']) {
                    return Http::response(['action' => 'would_set_flag', 'before' => $before, 'flag_only' => true], 200);
                }

                return Http::response([
                    'action' => 'set_flag',
                    'before' => $before,
                    'after' => array_merge($before, ['vvip_flag' => true, 'is_vvip' => true]),
                    'flag_only' => true,
                ], 200);
            },
        ]);
    }

    private function clientWithVvipDeal(Platform $platform, int $wpPostId): Client
    {
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => $wpPostId,
            'client_type' => 'escort',
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
            'plan_type' => 'vvip',
            'status' => 'active',
            'activated_at' => now()->subDays(3),
            'expires_at' => now()->addDays(20),
        ]);

        return $client;
    }

    private function createPlatform(string $name, string $code): Platform
    {
        return Platform::query()->create([
            'name' => $name,
            'domain' => $code.'-'.Str::random(6).'.example.test',
            'country' => $name,
            'timezone' => 'Africa/Nairobi',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
            'lifecycle_policy_enabled' => true,
            'wp_api_url' => "https://{$code}.example.test/wp-json/exotic-crm-sync/v1",
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }
}
