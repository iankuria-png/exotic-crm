<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\User;
use App\Services\ActiveSubscriptionProfileRepairService;
use App\Services\ClientSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientProfileLockTest extends TestCase
{
    use RefreshDatabase;

    private Platform $platform;

    /** @var array<int, array<string, mixed>> WordPress state per post id */
    private array $remote = [];

    /** @var list<array<string, mixed>> */
    private array $lifecycleWrites = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = Platform::factory()->create([
            'wp_api_url' => 'https://lock.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'test',
            'wp_api_password' => 'test',
            'lifecycle_policy_enabled' => true,
        ]);

        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (preg_match('#/clients/(\d+)/lifecycle$#', $request->url(), $matches)) {
                $postId = (int) $matches[1];
                $this->lifecycleWrites[] = ['post_id' => $postId] + $request->data();
                $state = (string) $request['state'];
                $this->remote[$postId]['post_status'] = 'publish';
                $this->remote[$postId]['crm_lifecycle_state'] = $state === 'active' ? '' : $state;
                if ($state === 'active') {
                    $this->remote[$postId]['needs_payment'] = false;
                    $this->remote[$postId]['notactive'] = false;
                    if ((int) ($request['escort_expire'] ?? 0) > 0) {
                        $this->remote[$postId]['escort_expire'] = (int) $request['escort_expire'];
                    }
                }

                return Http::response(['ok' => true, 'post_status' => 'publish', 'crm_lifecycle_state' => $state]);
            }

            if (preg_match('#/clients/(\d+)$#', $request->url(), $matches) && isset($this->remote[(int) $matches[1]])) {
                return Http::response($this->remote[(int) $matches[1]]);
            }

            // Bio scrub and other side calls are quiet failures here.
            return Http::response(['message' => 'Unexpected request'], 422);
        });

        $this->actingRole('sales');
    }

    private function actingRole(string $role): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => [$this->platform->id],
        ]));
    }

    private function makeClient(array $attributes = [], array $remote = []): Client
    {
        $client = Client::factory()->create(array_merge([
            'platform_id' => $this->platform->id,
            'profile_status' => 'publish',
            'lifecycle_state' => 'active',
            'needs_payment' => false,
            'notactive' => false,
            'escort_expire' => now()->addWeek()->timestamp,
        ], $attributes));

        $this->remote[(int) $client->wp_post_id] = array_merge([
            'wp_post_id' => (int) $client->wp_post_id,
            'post_status' => $client->profile_status,
            'crm_lifecycle_state' => $client->lifecycle_state === 'active' ? '' : $client->lifecycle_state,
            'escort_expire' => $client->escort_expire,
            'needs_payment' => (bool) $client->needs_payment,
            'notactive' => (bool) $client->notactive,
            'name' => $client->name,
        ], $remote);

        return $client;
    }

    private function paidDeal(Client $client): Deal
    {
        return Deal::factory()->create([
            'platform_id' => $this->platform->id,
            'client_id' => $client->id,
            'plan_type' => 'vip',
            'status' => 'active',
            'expires_at' => now()->addWeek(),
        ]);
    }

    public function test_cs_locks_an_active_paid_profile_and_the_plan_keeps_running(): void
    {
        $client = $this->makeClient();
        $deal = $this->paidDeal($client);
        $expiry = (int) $client->escort_expire;

        $this->postJson("/api/crm/clients/{$client->id}/lock", [
            'reason_code' => 'verification_required',
            'note' => 'Waiting for new ID photos',
        ])->assertOk()->assertJsonPath('result.action', 'locked');

        $fresh = $client->fresh();
        $this->assertSame('expired', $fresh->lifecycle_state);
        $this->assertSame('publish', $fresh->profile_status);
        $this->assertTrue($fresh->isProfileLocked());
        $this->assertSame('verification_required', $fresh->profile_lock_reason_code);
        $this->assertSame('Waiting for new ID photos', $fresh->profile_lock_reason_note);
        $this->assertSame($expiry, (int) $this->remote[(int) $client->wp_post_id]['escort_expire']);
        $this->assertSame('active', $deal->fresh()->status);
        $this->assertSame('expired', $this->lifecycleWrites[0]['state']);
        $this->assertDatabaseHas('timeline_events', ['entity_id' => $client->id, 'event_type' => 'profile_locked']);
        $this->assertDatabaseHas('audit_log', ['entity_id' => $client->id, 'action' => 'client_profile_lock']);
    }

    public function test_sync_and_background_repair_do_not_undo_a_paid_lock(): void
    {
        $client = $this->makeClient();
        $this->paidDeal($client);
        $this->postJson("/api/crm/clients/{$client->id}/lock", ['reason_code' => 'policy_violation'])->assertOk();
        $writes = count($this->lifecycleWrites);

        // WordPress still reports a future expiry; the lock must hold in the CRM copy.
        (new ClientSyncService($this->platform))->syncOne((int) $client->wp_post_id);
        $this->assertSame('expired', $client->fresh()->lifecycle_state);

        $repair = app(ActiveSubscriptionProfileRepairService::class);
        $this->assertFalse($repair->affectedClients($this->platform->id)->contains('id', $client->id));
        $row = $repair->repairClient($client->fresh(), null, false, 'artisan_repair_active_subscription_profiles');
        $this->assertSame('profile_locked', $row['reason']);
        $this->assertSame($writes, count($this->lifecycleWrites));
        $this->assertTrue($client->fresh()->isProfileLocked());
    }

    public function test_an_activation_that_returns_wordpress_to_active_ends_the_lock(): void
    {
        $client = $this->makeClient();
        $this->paidDeal($client);
        $this->postJson("/api/crm/clients/{$client->id}/lock", ['reason_code' => 'fraud_or_dispute'])->assertOk();

        // A renewal clears crm_lifecycle_state in WordPress.
        $this->remote[(int) $client->wp_post_id]['crm_lifecycle_state'] = '';
        (new ClientSyncService($this->platform))->syncOne((int) $client->wp_post_id);

        $fresh = $client->fresh();
        $this->assertSame('active', $fresh->lifecycle_state);
        $this->assertNull($fresh->profile_locked_at);
    }

    public function test_unlocking_a_paid_profile_puts_it_live_again(): void
    {
        $client = $this->makeClient();
        $this->paidDeal($client);
        $this->postJson("/api/crm/clients/{$client->id}/lock", ['reason_code' => 'quality_review'])->assertOk();

        $this->postJson("/api/crm/clients/{$client->id}/unlock", ['note' => 'Photos fixed'])
            ->assertOk()
            ->assertJsonPath('outcome', 'active');

        $fresh = $client->fresh();
        $this->assertSame('active', $fresh->lifecycle_state);
        $this->assertNull($fresh->profile_locked_at);
        $this->assertSame('active', end($this->lifecycleWrites)['state']);
        $this->assertGreaterThan(now()->timestamp, (int) end($this->lifecycleWrites)['escort_expire']);
        $this->assertDatabaseHas('timeline_events', ['entity_id' => $client->id, 'event_type' => 'profile_unlocked']);
        $this->assertDatabaseHas('audit_log', ['entity_id' => $client->id, 'action' => 'client_profile_unlock']);
    }

    public function test_unlocking_without_a_paid_plan_only_lifts_the_hold(): void
    {
        $client = $this->makeClient(['escort_expire' => null]);
        $this->postJson("/api/crm/clients/{$client->id}/lock", ['reason_code' => 'client_request'])->assertOk();
        $writes = count($this->lifecycleWrites);

        $this->postJson("/api/crm/clients/{$client->id}/unlock")
            ->assertOk()
            ->assertJsonPath('outcome', 'stays_expired');

        $fresh = $client->fresh();
        $this->assertSame('expired', $fresh->lifecycle_state);
        $this->assertNull($fresh->profile_locked_at);
        $this->assertSame($writes, count($this->lifecycleWrites));
    }

    public function test_bulk_preview_classifies_each_profile_and_bulk_lock_republishes_offline_ones(): void
    {
        $public = $this->makeClient();
        $offline = $this->makeClient(['profile_status' => 'private', 'churned_at' => now()->subMonths(3)]);
        $alreadyExpired = $this->makeClient(['lifecycle_state' => 'expired']);
        $highRisk = $this->makeClient(['profile_status' => 'private', 'churned_at' => now()->subMonth(), 'is_high_risk' => true]);
        $neverLive = $this->makeClient(['profile_status' => 'private']);
        $paused = $this->makeClient(['profile_status' => 'private', 'notactive' => true, 'profile_paused_at' => now()]);
        $ids = [$public->id, $offline->id, $alreadyExpired->id, $highRisk->id, $neverLive->id, $paused->id];

        $preview = $this->postJson('/api/crm/clients/bulk-lock/preview', ['client_ids' => $ids])->assertOk();
        $preview->assertJsonPath('summary.lock', 1)
            ->assertJsonPath('summary.republish', 1)
            ->assertJsonPath('summary.already_locked', 1)
            ->assertJsonPath('summary.blocked', 3);
        $codes = collect($preview->json('rows'))->pluck('code', 'id');
        $this->assertSame('high_risk', $codes[$highRisk->id]);
        $this->assertSame('never_live', $codes[$neverLive->id]);
        $this->assertSame('paused', $codes[$paused->id]);

        $this->postJson('/api/crm/clients/bulk-lock', [
            'client_ids' => $ids,
            'reason_code' => 'policy_violation',
        ])->assertOk()
            ->assertJsonPath('summary.locked', 2)
            ->assertJsonPath('summary.skipped', 4)
            ->assertJsonPath('summary.failed', 0);

        $this->assertSame(['publish', 'expired'], [$offline->fresh()->profile_status, $offline->fresh()->lifecycle_state]);
        $this->assertTrue($offline->fresh()->isProfileLocked());
        $this->assertSame('private', $highRisk->fresh()->profile_status);
        $this->assertSame('private', $neverLive->fresh()->profile_status);
        $this->assertNull($alreadyExpired->fresh()->profile_locked_at);
    }

    public function test_lock_needs_a_valid_reason_and_a_note_for_other(): void
    {
        $client = $this->makeClient();

        $this->postJson("/api/crm/clients/{$client->id}/lock", [])->assertStatus(422);
        $this->postJson("/api/crm/clients/{$client->id}/lock", ['reason_code' => 'other'])->assertStatus(422);
        $this->assertSame('active', $client->fresh()->lifecycle_state);
        $this->assertSame([], $this->lifecycleWrites);
    }

    public function test_bulk_slices_are_capped_and_marketing_cannot_lock(): void
    {
        $this->postJson('/api/crm/clients/bulk-lock', [
            'client_ids' => range(1, 11),
            'reason_code' => 'policy_violation',
        ])->assertStatus(422);

        $client = $this->makeClient();
        $this->actingRole('marketing');
        $this->postJson("/api/crm/clients/{$client->id}/lock", ['reason_code' => 'policy_violation'])->assertForbidden();
    }

    public function test_locking_is_refused_when_the_market_lifecycle_is_off(): void
    {
        $client = $this->makeClient();
        $this->platform->forceFill(['lifecycle_policy_enabled' => false])->save();

        $this->postJson("/api/crm/clients/{$client->id}/lock", ['reason_code' => 'policy_violation'])
            ->assertStatus(409)
            ->assertJsonPath('reason_code', 'lifecycle_disabled');
        $this->assertSame([], $this->lifecycleWrites);
    }

    public function test_auto_archive_leaves_staff_locked_profiles_alone(): void
    {
        $locked = $this->makeClient(['escort_expire' => null]);
        $this->postJson("/api/crm/clients/{$locked->id}/lock", ['reason_code' => 'policy_violation'])->assertOk();
        $locked->forceFill(['lifecycle_expired_at' => now()->subYear()])->save();

        $this->artisan('crm:archive-expired', ['--days' => 0])->assertSuccessful();

        $this->assertSame('expired', $locked->fresh()->lifecycle_state);
    }
}
