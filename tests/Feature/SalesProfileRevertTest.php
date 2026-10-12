<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\LifecycleRestoreRun;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Services\ActiveSubscriptionProfileRepairService;
use App\Services\ClientLifecycleService;
use App\Services\ClientSyncService;
use App\Services\ExpiredSubscriptionReconciler;
use App\Services\PaymentCompletionService;
use App\Services\ProfileLifecycleRestoreService;
use App\Services\SubscriptionProvisioningService;
use App\Services\WpSyncService;
use App\Support\LifecycleRestoreEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalesProfileRevertTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Platform $platform;

    private array $remote;

    private bool $deactivated = false;

    private bool $failDeactivate = false;

    private bool $failReadback = false;

    private bool $failActivation = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->platform = Platform::factory()->create([
            'lifecycle_policy_enabled' => true,
            'timezone' => 'Africa/Nairobi',
            'wp_api_url' => 'https://revert.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'test', 'wp_api_password' => 'test',
        ]);
        $this->client = Client::factory()->create([
            'platform_id' => $this->platform->id,
            'lifecycle_state' => 'expired',
            'profile_status' => 'publish',
            'lifecycle_restored_at' => null,
            'lifecycle_restore_run_id' => null,
        ]);
        $this->remote = [
            'wp_post_id' => $this->client->wp_post_id,
            'post_status' => 'publish',
            'crm_lifecycle_state' => 'expired',
            'escort_expire' => now()->subWeek()->timestamp,
            'needs_payment' => false, 'notactive' => false,
        ];
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/activate')) {
                if ($this->failActivation) {
                    return Http::response(['message' => 'Activation unavailable'], 422);
                }
                $this->deactivated = false;
                $this->remote = array_merge($this->remote, ['post_status' => 'publish', 'crm_lifecycle_state' => 'active', 'escort_expire' => now()->addMonth()->timestamp, 'needs_payment' => false]);

                return Http::response(['success' => true, 'escort_expire' => $this->remote['escort_expire']]);
            }
            if (str_ends_with($request->url(), '/deactivate')) {
                if ($this->failDeactivate) {
                    return Http::response(['message' => 'Unavailable'], 422);
                }
                $this->deactivated = true;

                return Http::response(['success' => true]);
            }
            if (str_ends_with($request->url(), '/clients/'.$this->client->wp_post_id)) {
                return Http::response($this->deactivated && ! $this->failReadback
                    ? array_merge($this->remote, ['post_status' => 'private', 'crm_lifecycle_state' => 'active', 'escort_expire' => null, 'needs_payment' => true])
                    : $this->remote);
            }

            return Http::response(['message' => 'Unexpected request'], 422);
        });
        $this->actingRole('sales');
    }

    private function actingRole(string $role, bool $accessible = true): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => $role, 'status' => 'active',
            'assigned_market_ids' => $accessible ? [$this->platform->id] : [],
        ]));
    }

    private function revert(array $body = ['reason' => 'Advertiser requested website removal'])
    {
        return $this->postJson('/api/crm/clients/'.$this->client->id.'/revert', $body);
    }

    public static function allowedRoles(): array
    {
        return [['sales'], ['field_sales'], ['sub_admin'], ['admin']];
    }

    #[DataProvider('allowedRoles')]
    public function test_scoped_staff_can_revert_naturally_expired_profiles(string $role): void
    {
        $this->actingRole($role);
        $this->revert()->assertOk()->assertJsonPath('client.profile_status', 'private')
            ->assertJsonPath('client.can_revert_profile', false);
        $fresh = $this->client->fresh();
        $this->assertNotNull($fresh->profile_removal_requested_at);
        $this->assertNotNull($fresh->profile_reverted_at);
        $this->assertDatabaseHas('timeline_events', ['entity_id' => $fresh->id, 'event_type' => 'profile_removal_requested']);
        $this->assertDatabaseHas('timeline_events', ['entity_id' => $fresh->id, 'event_type' => 'profile_restore_reverted']);
        $this->assertDatabaseHas('audit_log', ['entity_id' => $fresh->id, 'action' => 'client_profile_revert_requested']);
        $this->assertDatabaseHas('audit_log', ['entity_id' => $fresh->id, 'action' => 'client_profile_reverted']);
    }

    public function test_recovered_archived_profile_can_be_reverted_without_sales_recovery_permissions(): void
    {
        $run = LifecycleRestoreRun::create(['platform_id' => $this->platform->id, 'mode' => 'live', 'status' => 'completed', 'batch_limit' => 1]);
        $this->client->update(['lifecycle_state' => 'archived', 'lifecycle_restored_at' => now(), 'lifecycle_restore_run_id' => $run->id]);
        $this->remote['crm_lifecycle_state'] = 'archived';
        $this->revert()->assertOk()->assertJsonPath('client.lifecycle_restored_at', null);
        $this->postJson('/api/crm/lifecycle-restore/runs/'.$run->id.'/revert')->assertForbidden();
    }

    public static function privateLifecycleStates(): array
    {
        return [['expired'], ['archived']];
    }

    #[DataProvider('privateLifecycleStates')]
    public function test_private_expired_or_archived_profile_can_record_removal_and_block_recovery(string $state): void
    {
        // Screenshot clients are already private/payment-required after expiry.
        $this->client->update(['profile_status' => 'private', 'lifecycle_state' => $state, 'needs_payment' => true, 'escort_expire' => null]);
        $this->remote = array_merge($this->remote, ['post_status' => 'private', 'crm_lifecycle_state' => $state, 'needs_payment' => true, 'escort_expire' => null]);
        $this->getJson('/api/crm/clients/'.$this->client->id)->assertOk()->assertJsonPath('can_revert_profile', true);
        $this->revert()->assertOk()->assertJsonPath('client.profile_status', 'private')->assertJsonPath('client.can_revert_profile', false);
        $fresh = $this->client->fresh();
        $this->assertNotNull($fresh->profile_removal_requested_at);
        $this->assertNotNull($fresh->profile_reverted_at);
        $this->assertDatabaseHas('timeline_events', ['entity_id' => $fresh->id, 'event_type' => 'profile_removal_requested']);
        $this->assertSame(0, (new LifecycleRestoreEligibility(['history_mode' => 'any_wp_profile']))->query($this->platform->id)->count());
    }

    public function test_private_profile_still_requires_expired_or_archived_state_and_no_paid_entitlement(): void
    {
        $this->client->update(['profile_status' => 'private', 'lifecycle_state' => 'active', 'needs_payment' => true]);
        $this->getJson('/api/crm/clients/'.$this->client->id)->assertOk()->assertJsonPath('can_revert_profile', false);
        $this->revert()->assertUnprocessable();
        Http::assertNothingSent();
        $this->client->update(['lifecycle_state' => 'expired']);
        $this->remote['post_status'] = 'private';
        $this->remote['escort_expire'] = now()->addWeek()->timestamp;
        $this->revert()->assertStatus(409)->assertJsonPath('reason_code', 'paid_entitlement');
        $this->assertNull($this->client->fresh()->profile_removal_requested_at);
        $this->assertFalse($this->deactivated);
    }

    public function test_wrong_market_and_marketing_are_denied_without_wordpress_calls(): void
    {
        $this->actingRole('sales', false);
        $this->revert()->assertForbidden();
        $this->actingRole('marketing');
        $this->revert()->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_reason_and_expired_advertiser_state_are_required(): void
    {
        $this->revert([])->assertUnprocessable();
        $this->revert(['reason' => '   '])->assertUnprocessable();
        $this->client->update(['lifecycle_state' => 'active']);
        $this->revert()->assertUnprocessable();
        $this->client->update(['lifecycle_state' => 'expired', 'client_type' => 'agency']);
        $this->revert()->assertUnprocessable();
        $this->client->update(['client_type' => 'escort', 'wp_post_id' => 0]);
        $this->revert()->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_current_crm_entitlement_blocks_removal_without_wordpress_calls(): void
    {
        Deal::factory()->create(['platform_id' => $this->platform->id, 'client_id' => $this->client->id, 'status' => 'active', 'expires_at' => now()->addWeek()]);
        $this->revert()->assertStatus(409)->assertJsonPath('reason_code', 'paid_entitlement');
        Http::assertNothingSent();
        $this->assertNull($this->client->fresh()->profile_removal_requested_at);
    }

    public function test_future_wordpress_expiry_and_changed_lifecycle_block_stale_crm_removal(): void
    {
        $this->remote['escort_expire'] = now()->addWeek()->timestamp;
        $this->revert()->assertStatus(409)->assertJsonPath('reason_code', 'paid_entitlement');
        $this->remote['escort_expire'] = now()->subWeek()->timestamp;
        $this->remote['crm_lifecycle_state'] = 'active';
        $this->revert()->assertUnprocessable();
        $this->assertFalse($this->deactivated);
        $this->assertNull($this->client->fresh()->profile_removal_requested_at);
    }

    public function test_unidentified_wordpress_readback_blocks_removal_before_intent_is_saved(): void
    {
        $this->remote['wp_post_id'] = 0;
        $this->revert()->assertStatus(502);
        $this->assertNull($this->client->fresh()->profile_removal_requested_at);
        $this->assertFalse($this->deactivated);
    }

    public function test_failed_deactivation_retains_audited_intent_and_retry_succeeds(): void
    {
        $this->failDeactivate = true;
        $this->revert()->assertStatus(502);
        $this->assertNotNull($this->client->fresh()->profile_removal_requested_at);
        $this->assertNull($this->client->fresh()->profile_reverted_at);
        $this->assertDatabaseHas('timeline_events', ['entity_id' => $this->client->id, 'event_type' => 'profile_removal_failed']);
        $this->assertSame(0, (new LifecycleRestoreEligibility(['history_mode' => 'any_wp_profile']))->query($this->platform->id)->count());
        $this->failDeactivate = false;
        $this->revert()->assertOk();
    }

    public function test_unconfirmed_readback_never_reports_success_and_partial_private_removal_can_retry(): void
    {
        $this->failReadback = true;
        $this->revert()->assertStatus(502);
        $this->assertNull($this->client->fresh()->profile_reverted_at);
        $this->failReadback = false;
        $this->remote = array_merge($this->remote, ['post_status' => 'private', 'crm_lifecycle_state' => 'active', 'escort_expire' => null]);
        $this->client->update(['profile_status' => 'private', 'lifecycle_state' => 'active']);
        $this->revert()->assertOk();
        $count = count(Http::recorded(fn ($request) => str_ends_with($request->url(), '/deactivate')));
        $this->revert()->assertOk();
        $this->assertCount($count, Http::recorded(fn ($request) => str_ends_with($request->url(), '/deactivate')));
    }

    public function test_busy_profile_returns_conflict(): void
    {
        config(['client_lifecycle.lock_wait_seconds' => 0]);
        $lock = Cache::lock('client-lifecycle-mutation:'.$this->client->id, 60);
        $this->assertTrue($lock->get());
        try {
            $this->revert()->assertStatus(409)->assertJsonPath('reason_code', 'lifecycle_busy');
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_single_and_bulk_sync_preserve_hold(): void
    {
        $this->revert()->assertOk();
        $sync = new ClientSyncService($this->platform);
        $sync->syncOne($this->client->wp_post_id);
        $method = new \ReflectionMethod($sync, 'applyBulkClients');
        // The production bulk path must never update CRM-owned hold columns.
        $method->invoke($sync, [array_merge($this->remote, ['post_status' => 'private'])], 'full');
        $this->assertNotNull($this->client->fresh()->profile_removal_requested_at);
        $this->assertNotNull($this->client->fresh()->profile_reverted_at);
    }

    public function test_stale_recovery_and_expiry_candidates_cannot_republish_held_profile(): void
    {
        $stale = $this->client->fresh();
        $this->revert()->assertOk();
        $reconciler = app(ExpiredSubscriptionReconciler::class);
        $reconciler->reconcileClient($stale, null, false);
        $run = LifecycleRestoreRun::create(['platform_id' => $this->platform->id, 'mode' => 'live', 'status' => 'queued', 'batch_limit' => 1]);
        $restorer = app(ProfileLifecycleRestoreService::class);
        $this->assertSame(0, $restorer->candidates($run)->count());
        $method = new \ReflectionMethod($restorer, 'restoreClient');
        $this->assertSame('skipped', $method->invoke($restorer, $stale, $run, WpSyncService::forPlatform($this->platform->id), new ClientSyncService($this->platform)));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/lifecycle'));
    }

    public function test_remote_repair_and_archive_do_not_publish_held_profiles(): void
    {
        $this->revert()->assertOk();
        $deal = Deal::factory()->create(['platform_id' => $this->platform->id, 'client_id' => $this->client->id, 'status' => 'active', 'expires_at' => now()->addWeek()]);
        $result = app(ActiveSubscriptionProfileRepairService::class)->repairClient($this->client, $deal);
        $this->assertSame('profile_removal_requested', $result['reason']);
        try {
            app(ClientLifecycleService::class)->archive($this->client, null);
            $this->fail('Held profiles must reject archive.');
        } catch (\App\Exceptions\ClientLifecycleMutationException $exception) {
            $this->assertSame('profile_removal_requested', $exception->reasonCode);
        }
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/lifecycle'));
        $this->assertSame('private', $this->client->fresh()->profile_status);
    }

    public function test_new_successful_activation_clears_hold_but_failed_or_duplicate_activation_does_not(): void
    {
        $this->revert()->assertOk();
        $deal = Deal::factory()->create(['platform_id' => $this->platform->id, 'client_id' => $this->client->id,
            'status' => 'pending', 'activated_at' => null, 'expires_at' => null, 'plan_type' => 'basic']);
        $this->failActivation = true;
        try {
            app(SubscriptionProvisioningService::class)->activateDeal($deal, ['duration_days' => 30]);
            $this->fail('Failed activation must throw.');
        } catch (\Illuminate\Http\Client\RequestException $exception) {
            $this->assertSame(422, $exception->response->status());
        }
        $this->assertNotNull($this->client->fresh()->profile_removal_requested_at);
        $this->failActivation = false;
        app(SubscriptionProvisioningService::class)->activateDeal($deal->fresh(), ['duration_days' => 30]);
        $this->assertNull($this->client->fresh()->profile_removal_requested_at);
        $this->assertNull($this->client->fresh()->profile_reverted_at);
        $this->assertSame('publish', $this->client->fresh()->profile_status);
        // An already-active duplicate must not authorize another return.
        $this->client->update(['profile_status' => 'private', 'profile_removal_requested_at' => now(), 'profile_reverted_at' => now()]);
        $before = count(Http::recorded(fn ($request) => str_ends_with($request->url(), '/activate')));
        app(SubscriptionProvisioningService::class)->activateDeal($deal->fresh());
        $this->assertNotNull($this->client->fresh()->profile_removal_requested_at);
        $this->assertSame('private', $this->client->fresh()->profile_status);
        $this->assertCount($before, Http::recorded(fn ($request) => str_ends_with($request->url(), '/activate')));
    }

    public function test_live_payment_completion_chain_can_restore_profile_once_without_duplicate_activation(): void
    {
        $this->revert()->assertOk();
        $deal = Deal::factory()->create(['platform_id' => $this->platform->id, 'client_id' => $this->client->id,
            'status' => 'awaiting_payment', 'activated_at' => null, 'expires_at' => null, 'plan_type' => 'basic']);
        $payment = Payment::factory()->create(['platform_id' => $this->platform->id, 'client_id' => $this->client->id,
            'deal_id' => $deal->id, 'product_id' => $deal->product_id, 'amount' => $deal->amount, 'currency' => $deal->currency,
            'purpose' => 'subscription', 'provider_environment' => 'live', 'status' => 'initiated', 'duration' => 'monthly']);
        $deal->update(['payment_id' => $payment->id]);
        $result = app(PaymentCompletionService::class)->complete($payment, ['amount' => $deal->amount, 'currency' => $deal->currency]);
        $this->assertTrue($result['provisioned']);
        $this->assertNull($this->client->fresh()->profile_removal_requested_at);
        $this->assertSame('active', $deal->fresh()->status);
        app(PaymentCompletionService::class)->complete($payment->fresh(), ['amount' => $deal->amount, 'currency' => $deal->currency]);
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/activate')));
    }

    public function test_seo_boost_cannot_clear_a_removal_hold(): void
    {
        $this->revert()->assertOk();
        $deal = Deal::factory()->create(['platform_id' => $this->platform->id, 'client_id' => $this->client->id,
            'status' => 'pending', 'origin' => 'seo_boost']);
        try {
            app(SubscriptionProvisioningService::class)->activateDeal($deal);
            $this->fail('SEO boost cannot republish a removed advertiser.');
        } catch (\App\Exceptions\ClientLifecycleMutationException $exception) {
            $this->assertSame('profile_removal_requested', $exception->reasonCode);
        }
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/activate'));
    }

    public function test_migration_is_additive_and_reversible_on_isolated_database(): void
    {
        $migration = require database_path('migrations/2026_10_12_000001_add_profile_removal_timestamps_to_clients.php');
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('clients', 'profile_removal_requested_at'));
        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('clients', 'profile_reverted_at'));
        $this->assertNull($this->client->fresh()->profile_removal_requested_at);
        $this->assertSame('publish', $this->client->fresh()->profile_status);
    }

    public static function legacyWriteOutcomes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('legacyWriteOutcomes')]
    public function test_legacy_manual_activation_clears_exact_profile_hold_after_wp_writes(bool $failWrites): void
    {
        $errors = [];
        \Illuminate\Support\Facades\Log::listen(function ($event) use (&$errors): void {
            if ($event->level === 'error') {
                $errors[] = $event->context['error'] ?? $event->message;
            }
        });
        $this->actingRole('admin');
        $this->client->update(['profile_removal_requested_at' => now(), 'profile_reverted_at' => now(), 'profile_status' => 'private']);
        $other = Client::factory()->create(['platform_id' => $this->platform->id, 'profile_removal_requested_at' => now()]);
        $product = \App\Models\Product::factory()->create(['platform_id' => $this->platform->id, 'name' => 'Basic Escort']);
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT, post_author INTEGER, guid TEXT);');
        if (! $failWrites) {
            $pdo->exec('CREATE TABLE postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT);');
            $phone = $pdo->prepare('INSERT INTO postmeta (post_id,meta_key,meta_value) VALUES (?,?,?)');
            $phone->execute([$this->client->wp_post_id, 'phone', '254700000001']);
        }
        $pdo->exec('CREATE TABLE options (option_name TEXT, option_value TEXT);');
        $statement = $pdo->prepare('INSERT INTO posts VALUES (?, ?, ?, ?, ?)');
        $statement->execute([$this->client->wp_post_id, 'escort', 'private', 123, 'http://local-fixture.test/profile']);
        \Illuminate\Support\Facades\DB::extend('mysql', fn ($config, $name) => new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '', ['name' => $name, 'driver' => 'sqlite']));
        try {
            $response = $this->postJson('/api/activate-profile', ['platform_id' => $this->platform->id, 'post_id' => $this->client->wp_post_id,
                'product_id' => $product->id, 'days' => 30]);
            if ($failWrites) {
                $response->assertStatus(500);
                $this->assertNotNull($this->client->fresh()->profile_removal_requested_at);

                return;
            }
            $this->assertSame(200, $response->status(), $response->getContent().json_encode($errors));
            $this->assertNull($this->client->fresh()->profile_removal_requested_at);
            $this->assertNull($this->client->fresh()->profile_reverted_at);
            $this->assertNotNull($other->fresh()->profile_removal_requested_at);
            $this->assertSame('publish', $pdo->query('SELECT post_status FROM posts')->fetchColumn());
            $this->assertNotFalse($pdo->query("SELECT meta_value FROM postmeta WHERE meta_key='subscription_end'")->fetchColumn());
        } finally {
            \Illuminate\Support\Facades\DB::purge('platform_'.$this->platform->id);
            \Illuminate\Support\Facades\DB::forgetExtension('mysql');
        }
    }
}
