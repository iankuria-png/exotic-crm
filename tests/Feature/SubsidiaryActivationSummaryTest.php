<?php

namespace Tests\Feature;

use App\Http\Controllers\CRM\FreeTrialApprovalController;
use App\Models\BillingSubscriptionRule;
use App\Models\Client;
use App\Models\Deal;
use App\Models\FreeTrialRequest;
use App\Models\Platform;
use App\Models\Product;
use App\Models\TrialActivationOperation;
use App\Models\User;
use App\Services\SubsidiaryActivationPreviewService;
use App\Services\SubsidiaryActivationSummaryService;
use App\Services\SubsidiaryClientResolver;
use App\Services\SubsidiaryTrialService;
use App\Services\SubsidiaryTrialWorkflow;
use App\Services\SubsidiaryWpIdentityLookup;
use App\Services\Trials\TrialActivationService;
use App\Services\Trials\TrialApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubsidiaryActivationSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertTrue(config('database.default') === 'sqlite' || (config('database.default') === 'mysql' && str_starts_with(config('database.connections.mysql.database'), 'crm_daily_review_test_')));
        if (config('database.default') === 'mysql') {
            \Tests\Support\WorkflowTestDatabase::reset();
        } else {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
        Http::preventStrayRequests();
    }

    private function fixture(bool $withTarget = true): array
    {
        $source = Platform::factory()->create(['phone_prefix' => '255', 'wp_api_url' => 'https://source.example.test/wp-json/exotic-crm-sync/v1']);
        $target = Platform::factory()->create(['phone_prefix' => '255', 'wp_api_url' => 'https://target.example.test/wp-json/exotic-crm-sync/v1']);
        $product = Product::factory()->create(['platform_id' => $source->id, 'tier' => 'vip', 'slug' => 'vip', 'is_active' => true, 'is_archived' => false]);
        $targetProduct = Product::factory()->create(['platform_id' => $target->id, 'tier' => 'vip', 'slug' => 'vip', 'is_active' => true, 'is_archived' => false]);
        $target->update(['product_id' => $targetProduct->id]);
        BillingSubscriptionRule::create(['market_id' => $target->id, 'free_trial_json' => ['enabled' => true, 'duration_days' => 9]]);
        $client = Client::factory()->create(['platform_id' => $source->id, 'phone_normalized' => '255712345678', 'email' => 'aryan@customer.test', 'profile_reverted_at' => null, 'profile_removal_requested_at' => null]);
        $main = Deal::factory()->create(['platform_id' => $source->id, 'client_id' => $client->id, 'product_id' => $product->id, 'status' => 'active', 'expires_at' => now()->addDays(7), 'activated_at' => now(), 'payment_id' => null, 'duration_days' => 9]);
        $other = $withTarget ? Client::factory()->create(['platform_id' => $target->id, 'phone_normalized' => $client->phone_normalized, 'email' => $client->email, 'profile_reverted_at' => null, 'profile_removal_requested_at' => null, 'profile_status' => 'private']) : null;
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        config(['subsidiary_trials.pairs' => [$source->id => $target->id]]);
        Sanctum::actingAs($admin);

        return [$source, $target, $main, $admin, $other];
    }

    private function requestFor(Deal $main, Platform $target, User $actor, ?Client $client = null): FreeTrialRequest
    {
        $input = ['deal_id' => $main->id, 'platform_id' => $target->id, 'client_id' => $client?->id, 'create_confirmed' => ! $client, 'duration_days' => 9, 'reason' => 'Introduce this advertiser in the paired market.'];
        $http = Request::create('/', 'POST');
        $http->setUserResolver(fn () => $actor);
        $terms = app(FreeTrialApprovalController::class)->terms($http, 'subsidiary', $input);
        $r = app(TrialApprovalService::class)->create($actor, $terms);
        $main->update(['pending_subsidiary_trial' => ['status' => 'pending', 'platform_id' => $target->id, 'trial_request_id' => $r->id]]);

        return $r;
    }

    private function published(Client $client, ?string $url = null, string $status = 'publish'): void
    {
        $host = rtrim(preg_replace('#/wp-json/.*#', '', $client->platform->wp_api_url), '/');
        Http::fake([$host.'/*' => Http::response(['wp_post_id' => $client->wp_post_id, 'wp_user_id' => $client->wp_user_id, 'post_status' => $status, 'phone' => $client->phone_normalized, 'email' => $client->email, 'wp_profile_permalink' => $url ?? $host.'/escort/aryan/'])]);
    }

    public function test_defaults_use_exact_configured_pair_and_current_scope(): void
    {
        [$source, $target, $main, $admin] = $this->fixture();
        $this->getJson('/api/crm/deals/'.$main->id.'/subsidiary-trial-targets')->assertOk()->assertJsonPath('recommended_platform_id', $target->id);
        $sales = User::factory()->create(['role' => 'sales', 'assigned_market_ids' => [$source->id]]);
        Sanctum::actingAs($sales);
        $this->getJson('/api/crm/deals/'.$main->id.'/subsidiary-trial-targets')->assertOk()->assertJsonPath('recommended_platform_id', null)->assertJsonPath('pair_unavailable', true)->assertJsonCount(0, 'targets');
        $this->getJson('/api/crm/deals/'.$main->id.'/subsidiary-trial-preview?platform_id='.$target->id)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_preview_finds_unique_profile_without_writes_or_site_requests_and_rejects_stale_token(): void
    {
        [$source, $target, $main, $admin, $client] = $this->fixture();
        $preview = $this->getJson('/api/crm/deals/'.$main->id.'/subsidiary-trial-preview?platform_id='.$target->id)->assertOk()->assertJsonPath('resolution.kind', 'existing')->assertJsonPath('resolution.client_id', $client->id)->json();
        $this->assertNotEmpty($preview['preview_token']);
        $this->assertArrayNotHasKey('identity', $preview['resolution']);
        $this->assertDatabaseCount('free_trial_requests', 0);
        Http::assertNothingSent();
        $main->client->update(['phone_normalized' => '255700000999']);
        $input = ['platform_id' => $target->id, 'client_id' => $client->id, 'duration_days' => 9, 'preview_token' => $preview['preview_token']];
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(SubsidiaryActivationPreviewService::class)->validate($admin, $main->fresh('client.platform'), $input, app(SubsidiaryTrialService::class)->previewForDeal($main->fresh(), $target, $client->id));
    }

    public function test_duplicate_phone_requires_selection_and_generated_email_is_not_identity(): void
    {
        [$source, $target, $main, $admin, $client] = $this->fixture();
        Client::factory()->create(['platform_id' => $target->id, 'phone_normalized' => $client->phone_normalized]);
        $r = $this->getJson('/api/crm/deals/'.$main->id.'/subsidiary-trial-preview?platform_id='.$target->id)->assertOk()->assertJsonPath('resolution.kind', 'ambiguous')->assertJsonPath('preview_token', null)->json();
        $this->assertCount(2, $r['resolution']['candidates']);
        $this->getJson('/api/crm/deals/'.$main->id.'/subsidiary-trial-preview?platform_id='.$target->id.'&client_id='.$client->id)->assertOk()->assertJsonPath('resolution.kind', 'existing');
        $this->assertSame('', app(SubsidiaryActivationPreviewService::class)->email('onboard+123@customers.test'));
    }

    public function test_existing_coverage_is_not_replaced_or_linked_to_another_deal(): void
    {
        [$source, $target, $main, $admin, $client] = $this->fixture();
        $coverage = Deal::factory()->create(['platform_id' => $target->id, 'client_id' => $client->id, 'status' => 'active', 'expires_at' => now()->addDays(20), 'linked_deal_id' => $main->id]);
        $before = $coverage->fresh()->getRawOriginal();
        $this->getJson('/api/crm/deals/'.$main->id.'/subsidiary-trial-preview?platform_id='.$target->id)->assertOk()->assertJsonPath('resolution.kind', 'already_active');
        $this->assertSame($before, $coverage->fresh()->getRawOriginal());
        $this->assertDatabaseCount('free_trial_requests', 0);
        Http::assertNothingSent();
    }

    public function test_later_coverage_cancels_request_terminally_and_releases_identity_without_new_service(): void
    {
        [$source, $target, $main, $admin, $client] = $this->fixture();
        $r = $this->requestFor($main, $target, $admin, $client);
        $coverage = Deal::factory()->create(['platform_id' => $target->id, 'client_id' => $client->id, 'status' => 'active', 'expires_at' => now()->addDays(20)]);
        $this->published($client);
        $result = app(TrialActivationService::class)->execute($r);
        $this->assertSame('canceled', $result->status);
        $this->assertNull($result->active_identity);
        $this->assertSame('existing_coverage', $result->activation_result_json['outcome']);
        $this->assertDatabaseCount('trial_activation_operations', 0);
        $this->assertDatabaseCount('payments', 0);
        $coverage->update(['expires_at' => now()->subDay()]);
        app(TrialActivationService::class)->execute($result);
        $this->assertDatabaseCount('trial_activation_operations', 0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_delayed_approval_holds_changed_source_identity(): void
    {
        [$source, $target, $main, $admin, $client] = $this->fixture();
        $r = $this->requestFor($main, $target, $admin, $client);
        $main->client->update(['phone_normalized' => '255700000999']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(TrialActivationService::class)->execute($r);
    }

    public function test_superseded_trial_cannot_dispatch_when_primary_later_activates(): void
    {
        [$source, $target, $main, $admin, $client] = $this->fixture();
        $r = $this->requestFor($main, $target, $admin, $client);
        DB::transaction(fn () => app(SubsidiaryTrialWorkflow::class)->supersede($main, $admin, null));
        $main->update(['pending_subsidiary_trial' => null]);
        $this->assertSame('canceled', $r->fresh()->status);
        app(TrialActivationService::class)->execute($r->fresh());
        $this->assertDatabaseCount('trial_activation_operations', 0);
        Http::assertNothingSent();
    }

    public function test_unattached_historical_request_cannot_borrow_primary_activation(): void
    {
        [$source, $target, $main, $admin, $client] = $this->fixture();
        $r = $this->requestFor($main, $target, $admin, $client);
        $main->update(['pending_subsidiary_trial' => null]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(TrialActivationService::class)->execute($r);
    }

    public function test_provisioning_claim_prevents_cancellation_even_without_activation_operation(): void
    {
        [$source, $target, $main, $admin, $client] = $this->fixture();
        $r = $this->requestFor($main, $target, $admin, $client);
        $r->update(['status' => 'activating', 'activation_result_json' => ['profile_resolution' => 'resolving']]);
        $this->postJson('/api/crm/free-trials/requests/'.$r->id.'/cancel', ['version' => $r->fresh()->version, 'reason' => 'Opt out while creation is running.'])->assertConflict();
        $this->assertNotNull($r->fresh()->active_identity);
        $this->assertDatabaseCount('trial_activation_operations', 0);
    }

    public function test_separate_primary_deals_cannot_reserve_the_same_target_customer(): void
    {
        [$source, $target, $main, $admin] = $this->fixture(false);
        $r = $this->requestFor($main, $target, $admin);
        $second = $main->replicate();
        $second->pending_subsidiary_trial = null;
        $second->save();
        try {
            $this->requestFor($second, $target, $admin);
            $this->fail('Duplicate reservation accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('free_trial_requests', 1);
        $this->assertNotNull($r->fresh()->active_identity);
    }

    public function test_failed_authoritative_lookup_never_calls_provisioning(): void
    {
        [$source, $target, $main, $admin] = $this->fixture(false);
        $lookup = \Mockery::mock(SubsidiaryWpIdentityLookup::class);
        $lookup->shouldReceive('find')->once()->andThrow(new \RuntimeException('Incomplete authoritative lookup'));
        $this->app->instance(SubsidiaryWpIdentityLookup::class, $lookup);
        $this->expectException(\RuntimeException::class);
        app(SubsidiaryClientResolver::class)->resolveFrozen($target, app(SubsidiaryActivationPreviewService::class)->seed($main, $target), (string) Str::uuid());
    }

    public function test_unknown_profile_recovery_without_operation_stays_held_on_absent_ledger(): void
    {
        [$source, $target, $main, $admin] = $this->fixture(false);
        $r = $this->requestFor($main, $target, $admin);
        $r->update(['status' => 'uncertain', 'activation_result_json' => ['profile_resolution' => 'unknown', 'provision_request_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64)]]);
        $lookup = \Mockery::mock(SubsidiaryWpIdentityLookup::class);
        $lookup->shouldReceive('provisionRecord')->once()->andReturn(null);
        $this->app->instance(SubsidiaryWpIdentityLookup::class, $lookup);
        $after = app(TrialActivationService::class)->recover($r);
        $this->assertSame('uncertain', $after->status);
        $this->assertSame($r->active_identity, $after->active_identity);
        $this->assertDatabaseCount('trial_activation_operations', 0);
        Http::assertNothingSent();
    }

    public function test_summary_confirms_actual_urls_and_redacts_revoked_target_access_without_writes(): void
    {
        [$source, $target, $main, $admin, $client] = $this->fixture();
        $coverage = Deal::factory()->create(['platform_id' => $target->id, 'client_id' => $client->id, 'status' => 'active', 'expires_at' => now()->addDays(3)]);
        $main->update(['pending_subsidiary_trial' => ['status' => 'already_active', 'platform_id' => $target->id, 'coverage_id' => $coverage->id, 'subsidiary_client_id' => $client->id]]);
        $this->published($main->client);
        $this->published($client);
        $this->getJson('/api/crm/deals/'.$main->id.'/activation-summary')->assertOk()->assertJsonPath('subsidiary.status', 'already_active')->assertJsonPath('subsidiary.public_url', 'https://target.example.test/escort/aryan/');
        $this->assertDatabaseCount('free_trial_requests', 0);
        $this->assertDatabaseCount('payments', 0);
        $sales = User::factory()->create(['role' => 'sales', 'assigned_market_ids' => [$source->id]]);
        Sanctum::actingAs($sales);
        $this->getJson('/api/crm/deals/'.$main->id.'/activation-summary')->assertOk()->assertJsonPath('subsidiary', ['status' => 'restricted']);
    }

    public function test_foreign_or_private_urls_never_become_shareable(): void
    {
        [$source, $target, $main] = $this->fixture();
        $this->published($main->client, 'https://foreign.test/stolen/');
        $this->assertNull(app(SubsidiaryActivationSummaryService::class)->read($main, auth()->user())['primary']['public_url']);
        $this->published($main->client, null, 'private');
        $this->assertNull(app(SubsidiaryActivationSummaryService::class)->read($main, auth()->user())['primary']['public_url']);
    }

    public function test_deferred_paid_primary_never_claims_active_service_or_copies_its_link(): void
    {
        [$source, $target, $main] = $this->fixture();
        $main->update(['status' => 'paid', 'activation_deferred_at' => now()]);
        $this->getJson('/api/crm/deals/'.$main->id.'/activation-summary')->assertOk()->assertJsonPath('primary.status', 'activation_pending')->assertJsonPath('primary.public_url', null);
        Http::assertNothingSent();
    }

    public function test_invalid_primary_input_cannot_leave_a_detached_trial_request(): void
    {
        [$source, $target, $main, $admin, $client] = $this->fixture();
        $main->update(['status' => 'pending', 'activated_at' => null, 'expires_at' => null]);
        $this->postJson('/api/crm/deals/'.$main->id.'/activate', ['payment_method' => 'manual', 'subsidiary_trial' => ['enabled' => true, 'platform_id' => $target->id, 'client_id' => $client->id, 'duration_days' => 9]])->assertUnprocessable();
        $this->assertDatabaseCount('free_trial_requests', 0);
        $this->assertNull($main->fresh()->pending_subsidiary_trial);
        Http::assertNothingSent();
    }

    public function test_changed_package_cannot_be_laundered_into_resolved_identity(): void
    {
        [$source, $target, $main, $admin] = $this->fixture(false);
        $r = $this->requestFor($main, $target, $admin);
        $terms = $r->terms_json;
        Product::findOrFail($terms['product_id'])->update(['tier' => 'premium']);
        $client = Client::factory()->create(['platform_id' => $target->id, 'phone_normalized' => $main->client->phone_normalized, 'email' => $main->client->email]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(SubsidiaryTrialWorkflow::class)->bind($r, $client);
    }

    public function test_expired_primary_is_not_a_ready_customer_message(): void
    {
        [$source, $target, $main] = $this->fixture();
        $main->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/crm/deals/'.$main->id.'/activation-summary')->assertOk()->assertJsonPath('primary.status', 'expired')->assertJsonPath('primary.public_url', null);
        Http::assertNothingSent();
    }

    public function test_non_subsidiary_source_hash_remains_compatible_with_existing_approvals(): void
    {
        [$source, $target, $main, $admin] = $this->fixture();
        $terms = ['action' => 'activate', 'platform_id' => $source->id, 'source_deal_id' => $main->id, 'client_ids' => [$main->client_id], 'product_id' => $main->product_id];
        $legacy = hash('sha256', json_encode(['clients' => Client::whereIn('id', $terms['client_ids'])->orderBy('id')->get()->map->only(['id', 'platform_id', 'wp_post_id', 'wp_user_id', 'profile_removal_requested_at'])->all(),
            'deal' => $main->fresh()->only(['id', 'platform_id', 'client_id', 'product_id', 'status', 'duration_days', 'expires_at', 'activated_at']),
            'product' => Product::find($main->product_id)->only(['id', 'platform_id', 'tier', 'is_active', 'is_archived'])], JSON_THROW_ON_ERROR));
        $this->assertSame($legacy, app(TrialApprovalService::class)->source($terms));
    }

    public function test_two_primary_deals_racing_for_uncached_target_identity_create_one_reservation(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Requires disposable MySQL and independent processes.');
        }
        [$source, $target, $main, $admin] = $this->fixture(false);
        $other = $main->replicate();
        $other->pending_subsidiary_trial = null;
        $other->save();
        $http = Request::create('/', 'POST');
        $http->setUserResolver(fn () => $admin);
        $barrier = sys_get_temp_dir().'/subsidiary-race-'.Str::uuid();
        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database'), 'DB_SOCKET' => config('database.connections.mysql.unix_socket'), 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'), 'DB_HOST' => 'localhost', 'CACHE_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync']);
        $jobs = [];
        foreach ([$main, $other] as $sourceDeal) {
            $terms = app(FreeTrialApprovalController::class)->terms($http, 'subsidiary', ['deal_id' => $sourceDeal->id, 'platform_id' => $target->id, 'create_confirmed' => true, 'duration_days' => 9, 'reason' => 'Concurrent target introduction.']);
            $proc = proc_open(['/usr/local/opt/php@8.2/bin/php', base_path('tests/Support/subsidiary-race-worker.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            fwrite($pipes[0], json_encode(['actor' => $admin->id, 'terms' => $terms, 'barrier' => $barrier]));
            fclose($pipes[0]);
            $this->assertSame("READY\n", fgets($pipes[1]));
            $jobs[] = [$proc, $pipes];
        }
        touch($barrier);
        $statuses = [];
        foreach ($jobs as [$proc, $pipes]) {
            $out = trim(stream_get_contents($pipes[1]));
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($proc), $err);
            $this->assertSame('', trim($err));
            $statuses[] = json_decode($out, true, 512, JSON_THROW_ON_ERROR)['status'];
        }
        unlink($barrier);
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertDatabaseCount('free_trial_requests', 1);
        $this->assertDatabaseCount('trial_activation_operations', 0);
        Http::assertNothingSent();
    }

    public function test_paid_primary_and_existing_target_trial_complete_as_one_sales_journey(): void
    {
        [$source, $target, $main, $admin, $client] = $this->fixture();
        $main->update(['status' => 'pending', 'activated_at' => null, 'expires_at' => null, 'amount' => 1000, 'duration_days' => 7]);
        $sourceClient = $main->client;
        $sent = false;
        Http::fake([
            'https://source.example.test/*' => Http::response(['success' => true, 'wp_post_id' => $sourceClient->wp_post_id, 'wp_user_id' => $sourceClient->wp_user_id, 'name' => $sourceClient->name, 'phone' => $sourceClient->phone_normalized, 'email' => $sourceClient->email, 'post_status' => 'publish', 'escort_expire' => now()->addDays(7)->timestamp]),
            'https://target.example.test/*' => function ($request) use ($client, &$sent) {
                if ($request->method() === 'POST') {
                    $sent = true;

                    return Http::response(['success' => true, 'escort_expire' => now()->addDays(9)->timestamp]);
                }

                return Http::response(['wp_post_id' => $client->wp_post_id, 'wp_user_id' => $client->wp_user_id, 'phone' => $client->phone_normalized, 'email' => $client->email, 'post_status' => $sent ? 'publish' : 'private', 'escort_expire' => $sent ? now()->addDays(9)->timestamp : 0]);
            },
        ]);
        $preview = $this->getJson('/api/crm/deals/'.$main->id.'/subsidiary-trial-preview?platform_id='.$target->id)->assertOk()->json();
        $this->postJson('/api/crm/deals/'.$main->id.'/activate', ['payment_method' => 'manual', 'payment_reference' => 'SALES-QA-123', 'subsidiary_trial' => ['enabled' => true, 'platform_id' => $target->id, 'client_id' => $client->id, 'duration_days' => $preview['duration']['days'], 'preview_token' => $preview['preview_token'], 'request_key' => (string) Str::uuid()]])->assertOk()->assertJsonPath('status', 'active');
        $this->assertSame('active', FreeTrialRequest::firstOrFail()->status);
        $this->assertSame(7, FreeTrialRequest::firstOrFail()->terms_json['duration_days']); // Matches the purchased primary package.
        $this->assertNotNull($main->fresh()->linked_deal_id);
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(1, \App\Models\Payment::where('amount', 0)->count());
        $this->assertSame(1, TrialActivationOperation::where('state', 'succeeded')->count());
    }

    public function test_legacy_retry_without_approval_creates_no_detached_reservation(): void
    {
        [$source, $target, $main] = $this->fixture();
        $main->update(['pending_subsidiary_trial' => ['status' => 'failed', 'platform_id' => $target->id]]);
        $this->postJson('/api/crm/deals/'.$main->id.'/subsidiary-trial-retry', [])->assertConflict();
        $this->assertDatabaseCount('free_trial_requests', 0);
        Http::assertNothingSent();
    }

    public function test_incomplete_cached_wp_identity_is_unavailable_instead_of_silently_ready(): void
    {
        [$source, $target, $main, $admin, $client] = $this->fixture();
        $client->update(['wp_user_id' => null]);
        $this->getJson('/api/crm/deals/'.$main->id.'/subsidiary-trial-preview?platform_id='.$target->id)->assertOk()->assertJsonPath('resolution.kind', 'unavailable')->assertJsonPath('preview_token', null);
        Http::assertNothingSent();
    }

    public function test_revoked_primary_market_access_blocks_delayed_trial_execution(): void
    {
        [$source, $target, $main, $admin, $client] = $this->fixture();
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [$source->id, $target->id]]);
        $r = $this->requestFor($main, $target, $sales, $client);
        $r = app(TrialApprovalService::class)->decide($admin, $r->fresh(), 'approve', $r->fresh()->version, 'Approved market introduction.');
        $sales->update(['assigned_market_ids' => [$target->id]]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(TrialActivationService::class)->execute($r);
    }

    public function test_primary_expiry_blocks_new_trial_even_with_stale_active_status(): void
    {
        [$source, $target, $main, $admin, $client] = $this->fixture();
        $r = $this->requestFor($main, $target, $admin, $client);
        $main->update(['expires_at' => now()->subMinute()]);
        app(TrialActivationService::class)->execute($r);
        $this->assertDatabaseCount('trial_activation_operations', 0);
        Http::assertNothingSent();
    }

    private function recoverKnownProfile(bool $expired): void
    {
        [$source, $target, $main, $admin] = $this->fixture(false);
        $r = $this->requestFor($main, $target, $admin);
        $id = (string) Str::uuid();
        $hash = str_repeat('a', 64);
        $r->update(['status' => 'uncertain', 'expires_at' => $expired ? now()->subMinute() : now()->addHour(), 'activation_result_json' => ['profile_resolution' => 'unknown', 'provision_request_id' => $id, 'payload_hash' => $hash]]);
        $lookup = \Mockery::mock(SubsidiaryWpIdentityLookup::class);
        $lookup->shouldReceive('provisionRecord')->once()->with(\Mockery::type(Platform::class), $id)->andReturn(['status' => 'completed', 'payload_hash' => $hash, 'wp_post_id' => 10101, 'wp_user_id' => 20202]);
        $this->app->instance(SubsidiaryWpIdentityLookup::class, $lookup);
        Http::fake(['https://target.example.test/*' => Http::response(['wp_post_id' => 10101, 'wp_user_id' => 20202, 'name' => $main->client->name, 'phone' => $main->client->phone_normalized, 'email' => $main->client->email, 'city' => $main->client->city, 'post_status' => 'private', 'client_type' => 'escort'])]);
        $out = app(TrialActivationService::class)->recover($r);
        $this->assertSame($expired ? 'pending' : 'approved', $out->status);
        $this->assertSame('resolved', $out->activation_result_json['profile_resolution']);
        $this->assertNotNull($out->client_id);
        $this->assertNotNull($out->active_identity);
        $this->assertDatabaseCount('trial_activation_operations', 0);
        $this->assertDatabaseCount('payments', 0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_completed_provision_ledger_recovers_exact_profile_without_trial_dispatch(): void
    {
        $this->recoverKnownProfile(false);
    }

    public function test_expired_authorization_requires_new_decision_after_profile_recovery(): void
    {
        $this->recoverKnownProfile(true);
    }

    public function test_market_disabled_after_approval_cannot_provision_a_profile(): void
    {
        [$source, $target, $main, $admin] = $this->fixture(false);
        $r = $this->requestFor($main, $target, $admin);
        BillingSubscriptionRule::where('market_id', $target->id)->first()->update(['free_trial_json' => ['enabled' => false]]);
        $resolver = \Mockery::mock(SubsidiaryClientResolver::class, [app(\App\Support\CrossPlatformPhoneResolver::class)])->makePartial();
        $resolver->shouldNotReceive('resolveFrozen');
        $this->app->instance(SubsidiaryClientResolver::class, $resolver);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(TrialActivationService::class)->execute($r);
    }

    public function test_subsidiary_request_key_reuses_saved_terms_after_json_key_reordering(): void
    {
        [$source, $target, $main, $admin, $client] = $this->fixture();
        $request = $this->requestFor($main, $target, $admin, $client);
        $terms = array_reverse($request->fresh()->terms_json, true);
        $same = app(TrialApprovalService::class)->create($admin, $terms, null, $request->request_key);
        $this->assertSame($request->id, $same->id);
        $this->assertDatabaseCount('free_trial_requests', 1);
    }

    public function test_cached_match_with_changed_site_phone_cannot_receive_a_trial(): void
    {
        [$source, $target, $main, $admin, $client] = $this->fixture();
        $r = $this->requestFor($main, $target, $admin, $client);
        Http::fake(['https://target.example.test/*' => Http::response(['wp_post_id' => $client->wp_post_id, 'wp_user_id' => $client->wp_user_id, 'phone' => '255700000999', 'email' => $client->email, 'post_status' => 'private'])]);
        try {
            app(TrialActivationService::class)->execute($r);
            $this->fail('Changed site identity was accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        $this->assertDatabaseCount('trial_activation_operations', 0);
    }

    private function siteDatabase(Platform $target): \Illuminate\Database\Connection
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Complete authoritative scan requires disposable MySQL.');
        }
        config(['database.connections.subsidiary_fixture' => array_merge(config('database.connections.mysql'), ['prefix' => 'wp_subsidiary_'])]);
        DB::purge('subsidiary_fixture');
        $db = DB::connection('subsidiary_fixture');
        foreach (['postmeta', 'posts', 'users', 'options'] as $table) {
            $db->getSchemaBuilder()->dropIfExists($table);
        }
        $schema = $db->getSchemaBuilder();
        $schema->create('options', function ($t) {
            $t->string('option_name');
            $t->text('option_value');
        });
        $schema->create('posts', function ($t) {
            $t->unsignedBigInteger('ID')->primary();
            $t->unsignedBigInteger('post_author');
            $t->string('post_type');
            $t->string('post_status');
        });
        $schema->create('postmeta', function ($t) {
            $t->unsignedBigInteger('post_id');
            $t->string('meta_key');
            $t->text('meta_value');
        });
        $schema->create('users', function ($t) {
            $t->unsignedBigInteger('ID')->primary();
            $t->string('user_email');
        });
        $db->table('options')->insert([['option_name' => 'siteurl', 'option_value' => 'https://target.example.test'], ['option_name' => 'taxonomy_profile_url', 'option_value' => 'profile']]);
        $db->table('users')->insert(['ID' => 55, 'user_email' => 'aryan@customer.test']);
        $rows = [];
        for ($id = 1; $id <= 520; $id++) {
            $rows[] = ['ID' => $id, 'post_author' => 55, 'post_type' => 'profile', 'post_status' => 'private'];
        }
        // Nonmatching emails ensure the target phone on page two establishes the single exact identity.
        $db->table('users')->where('ID', 55)->update(['user_email' => 'onboarding@placeholder.test']);
        $db->table('posts')->insert($rows);
        $db->table('postmeta')->insert(['post_id' => 510, 'meta_key' => 'phone', 'meta_value' => '0712345678']);

        return $db;
    }

    public function test_complete_authoritative_lookup_finds_a_profile_beyond_first_page_with_custom_prefix_and_type(): void
    {
        [$source, $target, $main] = $this->fixture(false);
        $db = $this->siteDatabase($target);
        $lookup = \Mockery::mock(SubsidiaryWpIdentityLookup::class)->makePartial();
        $lookup->shouldReceive('connection')->once()->andReturn($db);
        $match = $lookup->find($target, app(SubsidiaryActivationPreviewService::class)->seed($main, $target));
        $this->assertSame(['wp_post_id' => 510, 'wp_user_id' => 55], $match);
        $this->assertSame(520, $db->table('posts')->count());
    }

    public static function siteHostCases(): array
    {
        return [
            'Tanzania www site with bare API host' => ['https://www.target.example.test', 'https://target.example.test/wp-json/exotic-crm-sync/v1', true],
            'bare site with www API host' => ['https://target.example.test', 'https://www.target.example.test/wp-json/exotic-crm-sync/v1', true],
            'case insensitive host' => ['https://WWW.Target.Example.Test', 'https://target.example.test/wp-json/exotic-crm-sync/v1', true],
            'different market' => ['https://another.example.test', 'https://target.example.test/wp-json/exotic-crm-sync/v1', false],
            'lookalike domain' => ['https://target.example.test.attacker.test', 'https://target.example.test/wp-json/exotic-crm-sync/v1', false],
            'different subdomain' => ['https://admin.target.example.test', 'https://target.example.test/wp-json/exotic-crm-sync/v1', false],
            'missing host' => ['', '', false],
        ];
    }

    /** @dataProvider siteHostCases */
    public function test_authoritative_lookup_accepts_only_the_same_market_host_and_its_www_alias(string $siteurl, string $apiUrl, bool $accepted): void
    {
        [$source, $target, $main] = $this->fixture(false);
        $db = $this->siteDatabase($target);
        $db->table('options')->where('option_name', 'siteurl')->update(['option_value' => $siteurl]);
        $target->update(['wp_api_url' => $apiUrl]);
        $lookup = \Mockery::mock(SubsidiaryWpIdentityLookup::class)->makePartial();
        $lookup->shouldReceive('connection')->once()->andReturn($db);
        if (! $accepted) {
            $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
            $this->expectExceptionMessage('The target database belongs to a different site.');
        }
        $match = $lookup->find($target, app(SubsidiaryActivationPreviewService::class)->seed($main, $target));
        $this->assertSame(['wp_post_id' => 510, 'wp_user_id' => 55], $match);
        $this->assertSame(520, $db->table('posts')->count());
        Http::assertNothingSent();
    }

    public function test_incomplete_authoritative_scan_cannot_establish_absence(): void
    {
        [$source, $target, $main] = $this->fixture(false);
        $db = $this->siteDatabase($target);
        config(['subsidiary_trials.lookup_limit' => 200]);
        $lookup = \Mockery::mock(SubsidiaryWpIdentityLookup::class)->makePartial();
        $lookup->shouldReceive('connection')->once()->andReturn($db);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $lookup->find($target, app(SubsidiaryActivationPreviewService::class)->seed($main, $target));
    }
}
