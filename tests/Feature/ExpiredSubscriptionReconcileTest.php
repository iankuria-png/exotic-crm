<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\ExpiryReconciliationRun;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpiredSubscriptionReconcileTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_force_expires_stuck_profile_and_expires_active_deal(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createStuckClient($platform, 124001);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'active',
        ]);

        $this->fakeWpDeactivation($platform, 124001);

        $this->artisan('crm:reconcile-expired-subscriptions')
            ->assertExitCode(0);

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'profile_status' => 'private',
            'escort_expire' => null,
        ]);
        $this->assertSame('expired', $deal->fresh()->status);

        $this->assertDatabaseHas('timeline_events', [
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'profile_deactivated',
        ]);

        $run = ExpiryReconciliationRun::query()->latest('id')->firstOrFail();
        $this->assertSame('live', $run->mode);
        $this->assertSame(1, $run->processed);
        $this->assertSame(0, $run->failed);
    }

    public function test_dry_run_changes_nothing_but_records_a_run(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createStuckClient($platform, 124002);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'active',
        ]);

        Http::fake();

        $this->artisan('crm:reconcile-expired-subscriptions', ['--dry-run' => true])
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame('publish', $client->fresh()->profile_status);
        $this->assertSame('active', $deal->fresh()->status);

        $run = ExpiryReconciliationRun::query()->latest('id')->firstOrFail();
        $this->assertSame('dry', $run->mode);
        $this->assertSame(1, $run->processed);
    }

    public function test_command_ignores_future_expiry_and_premium_only_lapse(): void
    {
        $platform = $this->createPlatform();

        // Profile access still valid (future escort_expire) — must be left alone.
        $future = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 124003,
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'escort_expire' => now()->addDays(20)->timestamp,
        ]);

        // Only the premium add-on lapsed; the profile itself is still paid.
        $premiumOnly = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 124004,
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'escort_expire' => now()->addDays(20)->timestamp,
            'premium' => true,
            'premium_expire' => now()->subDays(5)->timestamp,
        ]);

        Http::fake();

        $this->artisan('crm:reconcile-expired-subscriptions')
            ->expectsOutputToContain('Found 0 stuck profile(s).')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame('publish', $future->fresh()->profile_status);
        $this->assertSame('publish', $premiumOnly->fresh()->profile_status);
    }

    public function test_expire_now_endpoint_deactivates_a_stuck_profile(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createStuckClient($platform, 124005);
        Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'active',
        ]);
        $this->fakeWpDeactivation($platform, 124005);

        Sanctum::actingAs($this->createAuthorizedUser($platform));

        $this->postJson("/api/crm/clients/{$client->id}/expire-now")
            ->assertOk()
            ->assertJsonPath('client.profile_status', 'private')
            ->assertJsonPath('client.expiry_state', null)
            ->assertJsonPath('result.action', 'deactivated');

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'profile_status' => 'private',
        ]);
    }

    public function test_expire_now_rejects_a_profile_that_is_not_expired(): void
    {
        $platform = $this->createPlatform();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 124006,
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'escort_expire' => now()->addDays(20)->timestamp,
        ]);

        Http::fake();
        Sanctum::actingAs($this->createAuthorizedUser($platform));

        $this->postJson("/api/crm/clients/{$client->id}/expire-now")
            ->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame('publish', $client->fresh()->profile_status);
    }

    public function test_index_filter_lists_stuck_profiles_with_count_and_state(): void
    {
        $platform = $this->createPlatform();
        $stuck = $this->createStuckClient($platform, 124007);
        Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 124008,
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'escort_expire' => now()->addDays(20)->timestamp,
        ]);

        Sanctum::actingAs($this->createAuthorizedUser($platform));

        $response = $this->getJson("/api/crm/clients?status=expired_public&platform_id={$platform->id}")
            ->assertOk()
            ->assertJsonPath('stats.expired_public', 1);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$stuck->id], $ids);
        $this->assertSame('expired_public', $response->json('data.0.expiry_state'));
    }

    public function test_bulk_expire_only_deactivates_eligible_and_reports_skips(): void
    {
        $platform = $this->createPlatform();
        $stuck = $this->createStuckClient($platform, 124009);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $stuck->id,
            'status' => 'active',
        ]);
        $active = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 124010,
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'escort_expire' => now()->addDays(20)->timestamp,
        ]);
        $this->fakeWpDeactivation($platform, 124009);

        Sanctum::actingAs($this->createAuthorizedUser($platform));

        $this->postJson('/api/crm/clients/bulk-expire', [
            'client_ids' => [$stuck->id, $active->id],
        ])
            ->assertOk()
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.expired', 1)
            ->assertJsonPath('summary.skipped', 1)
            ->assertJsonPath('summary.failed', 0);

        $this->assertSame('private', $stuck->fresh()->profile_status);
        $this->assertSame('expired', $deal->fresh()->status);
        $this->assertSame('publish', $active->fresh()->profile_status);
    }

    public function test_bulk_expire_reports_clients_outside_the_reps_market(): void
    {
        $platform = $this->createPlatform();
        $otherPlatform = $this->createPlatform();
        $stuck = $this->createStuckClient($platform, 124012);
        $foreign = $this->createStuckClient($otherPlatform, 124013);
        $this->fakeWpDeactivation($platform, 124012);

        $sales = User::query()->create([
            'name' => 'Scoped Sales',
            'email' => 'scoped-' . Str::random(6) . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'sales',
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);
        Sanctum::actingAs($sales);

        $response = $this->postJson('/api/crm/clients/bulk-expire', [
            'client_ids' => [$stuck->id, $foreign->id],
        ])->assertOk()
            ->assertJsonPath('summary.expired', 1)
            ->assertJsonPath('summary.failed', 1);

        $forbidden = collect($response->json('results'))->firstWhere('client_id', $foreign->id);
        $this->assertSame('failed', $forbidden['action']);
        $this->assertSame('forbidden', $forbidden['error']);
        $this->assertSame('publish', $foreign->fresh()->profile_status);
    }

    private function createStuckClient(Platform $platform, int $wpPostId): Client
    {
        return Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => $wpPostId,
            'wp_user_id' => $wpPostId + 1000,
            'name' => 'Judy ' . $wpPostId,
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'escort_expire' => now()->subDays(3)->timestamp,
        ]);
    }

    private function fakeWpDeactivation(Platform $platform, int $wpPostId): void
    {
        $base = rtrim((string) $platform->wp_api_url, '/');

        Http::fake([
            "{$base}/clients/{$wpPostId}/deactivate" => Http::response(['success' => true], 200),
            "{$base}/clients/{$wpPostId}" => Http::response([
                'wp_post_id' => $wpPostId,
                'wp_user_id' => $wpPostId + 1000,
                'name' => 'Judy ' . $wpPostId,
                'phone' => '+255700000000',
                'email' => "judy{$wpPostId}@example.test",
                'city' => 'Kigamboni',
                'post_status' => 'private',
                'premium' => false,
                'premium_expire' => null,
                'featured' => false,
                'featured_expire' => null,
                'escort_expire' => null,
                'verified' => false,
                'needs_payment' => true,
                'notactive' => false,
                'main_image_url' => '',
                'modified_at' => now()->toIso8601String(),
            ], 200),
        ]);
    }

    private function createPlatform(): Platform
    {
        return Platform::query()->create([
            'name' => 'Tanzania Market',
            'domain' => 'tz-' . Str::random(6) . '.example.test',
            'country' => 'Tanzania',
            'timezone' => 'Africa/Dar_es_Salaam',
            'phone_prefix' => '255',
            'currency_code' => 'TZS',
            'is_active' => true,
            'wp_api_url' => 'https://tz.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function createAuthorizedUser(Platform $platform): User
    {
        return User::query()->create([
            'name' => 'Reconcile Admin',
            'email' => 'reconcile-' . Str::random(6) . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);
    }
}
