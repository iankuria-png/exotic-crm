<?php

namespace Tests\Feature;

use App\Http\Controllers\CRM\FreeTrialApprovalController;
use App\Models\BillingSubscriptionRule;
use App\Models\Client;
use App\Models\Deal;
use App\Models\FreeTrialRequest;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\SubsidiaryProfileCopyItem;
use App\Models\SubsidiaryProfileCopyRun;
use App\Models\TrialActivationOperation;
use App\Models\User;
use App\Services\SubsidiaryClientResolver;
use App\Services\SubsidiaryProfileCopyService;
use App\Services\SubsidiaryPromotionPolicyService;
use App\Services\Trials\TrialActivationService;
use App\Services\Trials\TrialApprovalService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** One-time TZ → Tanzania public-profile copy against stateful fake source and target sites. */
class SubsidiaryProfileCopyTest extends TestCase
{
    private array $site = [];

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
        Cache::flush();
        Http::preventStrayRequests();
        config(['subsidiary_trials.profile_copy_enabled' => true]);
    }

    private function fixture(int $purchasedDays = 14): array
    {
        $source = Platform::factory()->create(['name' => 'TZ', 'phone_prefix' => '255', 'wp_api_url' => 'https://source.example.test/wp-json/exotic-crm-sync/v1']);
        $target = Platform::factory()->create(['name' => 'Tanzania', 'phone_prefix' => '255', 'wp_api_url' => 'https://target.example.test/wp-json/exotic-crm-sync/v1']);
        $product = Product::factory()->create(['platform_id' => $source->id, 'tier' => 'vip', 'slug' => 'vip', 'is_active' => true, 'is_archived' => false]);
        $price = ProductPrice::create(['product_id' => $product->id, 'duration_key' => 'custom', 'duration_label' => $purchasedDays.' days', 'duration_days' => $purchasedDays, 'price' => 1000, 'currency' => 'TZS', 'is_active' => true]);
        $targetProduct = Product::factory()->create(['platform_id' => $target->id, 'tier' => 'vip', 'slug' => 'vip', 'is_active' => true, 'is_archived' => false]);
        $target->update(['product_id' => $targetProduct->id]);
        BillingSubscriptionRule::create(['market_id' => $target->id, 'free_trial_json' => ['enabled' => true, 'duration_days' => 7]]);
        $client = Client::factory()->create(['platform_id' => $source->id, 'name' => 'Dev Test PM', 'phone_normalized' => '255712345678', 'email' => 'devtest@customer.test', 'profile_reverted_at' => null, 'profile_removal_requested_at' => null]);
        $main = Deal::factory()->create(['platform_id' => $source->id, 'client_id' => $client->id, 'product_id' => $product->id, 'product_price_id' => $price->id, 'duration_days' => $purchasedDays,
            'status' => 'active', 'expires_at' => now()->addDays($purchasedDays), 'activated_at' => now(), 'payment_id' => null]);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        config(['subsidiary_trials.pairs' => [$source->id => $target->id], 'subsidiary_trials.profile_copy_default_pairs' => [$source->id => $target->id]]);
        Sanctum::actingAs($admin);
        $this->fakeSites($client, $main->client->wp_user_id);

        return [$source, $target, $main, $admin, $client];
    }

    /** Stateful fake: source export plus a target ledger that completes the same item once. */
    private function fakeSites(Client $source, int $sourceUser): void
    {
        $this->site = ['bytes' => [501 => 'photo-one-bytes', 502 => 'photo-two-bytes', 503 => 'video-bytes'], 'items' => [], 'attachments' => [], 'bound' => null, 'state' => 'bound',
            'post_status' => 'private', 'fields_digest' => 'baseline', 'location' => [], 'main' => 0, 'next_attachment' => 9000, 'fail_once' => [], 'expire' => 0, 'posts' => [], 'lose_response' => [], 'calls' => []];
        $media = [];
        foreach ([[501, 'photo', 'image/jpeg', 2, true], [502, 'photo', 'image/png', 1, false], [503, 'video', 'video/mp4', 3, false]] as [$id, $kind, $mime, $order, $main]) {
            $media[] = ['id' => $id, 'mime_type' => $mime, 'kind' => $kind, 'size' => strlen($this->site['bytes'][$id]), 'sha256' => hash('sha256', $this->site['bytes'][$id]), 'menu_order' => $order, 'alt_text' => 'Alt '.$id, 'is_main' => $main, 'filename' => $id.'.bin'];
        }
        usort($media, fn ($a, $b) => [$a['menu_order'], $a['id']] <=> [$b['menu_order'], $b['id']]);
        $this->site['export'] = ['contract' => 1, 'post' => ['id' => $source->wp_post_id, 'author_id' => $sourceUser, 'status' => 'private', 'type' => 'escort', 'title' => 'Dev Test PM', 'content' => '<p>Bio “Karibu” ✓</p>', 'excerpt' => ''],
            'fields' => ['whatsapp' => '0712345678', 'instagram' => 'devtest', 'rate1h_incall' => '50000', 'services' => ['4', '8']], 'currency' => ['id' => 12, 'code' => 'TZS'],
            'location' => ['region' => ['name' => 'Dar es Salaam', 'slug' => 'dar-es-salaam'], 'city' => ['name' => 'Airport', 'slug' => 'airport']], 'tags' => ['Elite'],
            'media' => $media, 'main_image_id' => 501, 'unsupported_bio_keys' => []];
        $caps = ['contract' => 1, 'export' => true, 'import' => true, 'limits' => ['max_images' => 20, 'max_videos' => 5, 'max_image_bytes' => 5242880, 'max_video_bytes' => 52428800, 'mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'video/mp4']], 'currencies' => [['id' => 7, 'code' => 'TZS']]];
        $locations = ['locations' => [['id' => 40, 'name' => 'Dar es Salaam', 'slug' => 'dar-es-salaam', 'cities' => [['id' => 41, 'name' => 'Airport', 'slug' => 'airport'], ['id' => 42, 'name' => 'Kariakoo', 'slug' => 'kariakoo']]]]];
        $base = 'https://source.example.test/wp-json/exotic-crm-sync/v1';
        Http::fake([
            $base.'/profile-copy/capabilities' => Http::response($caps),
            $base.'/clients/'.$source->wp_post_id.'/copy-export' => fn () => Http::response($this->site['export']),
            $base.'/clients/'.$source->wp_post_id.'/copy-export/media/*' => function (HttpRequest $r) {
                $id = (int) basename($r->url());
                $this->site['calls'][] = 'download:'.$id;

                return Http::response($this->site['bytes'][$id] ?? '', isset($this->site['bytes'][$id]) ? 200 : 404);
            },
            'https://target.example.test/*' => fn (HttpRequest $r) => $this->target($r, $caps, $locations),
        ]);
    }

    private function target(HttpRequest $r, array $caps, array $locations)
    {
        $path = preg_replace('#^/wp-json/exotic-crm-sync/v1#', '', (string) parse_url($r->url(), PHP_URL_PATH));
        $this->site['calls'][] = $r->method().' '.$path;
        if ($path === '/profile-copy/capabilities') {
            return Http::response($caps);
        }
        if ($path === '/locations') {
            return Http::response($locations);
        }
        if (preg_match('#^/clients/(\d+)/profile-copy/([0-9a-f-]{36})/bind$#', $path, $m)) {
            $body = $r->data();
            if ((int) $body['wp_user_id'] !== 20202) {
                return Http::response(['code' => 'not_created_by_run'], 403);
            }
            $this->site['bound'] = $m[2];

            return Http::response(['run_key' => $m[2], 'state' => 'bound', 'post_id' => (int) $m[1], 'user_id' => 20202, 'baseline_digest' => 'baseline', 'post_status' => 'private']);
        }
        if (preg_match('#^/clients/(\d+)/profile-copy/([0-9a-f-]{36})/state$#', $path, $m)) {
            $this->site['state'] = $r->data()['state'];

            return Http::response(['state' => $this->site['state']]);
        }
        if (preg_match('#^/clients/(\d+)/profile-copy/([0-9a-f-]{36})$#', $path, $m)) {
            return Http::response(['run_key' => $m[2], 'state' => $this->site['state'], 'post_id' => (int) $m[1], 'post_status' => $this->site['post_status'], 'fields_digest' => $this->site['fields_digest'],
                'location_term_ids' => $this->site['location'], 'main_image_id' => $this->site['main'], 'attachments' => array_values($this->site['attachments'])]);
        }
        if (preg_match('#^/clients/(\d+)/profile-copy/([0-9a-f-]{36})/(fields|media-\d+)$#', $path, $m)) {
            $key = $m[3];
            if ($r->method() === 'GET') {
                return Http::response($this->site['items'][$key] ?? ['item_key' => $key, 'state' => 'absent']);
            }
            $data = $this->formData($r);
            $command = is_array($data['command']) ? $data['command'] : json_decode($data['command'], true);
            $hash = hash('sha256', json_encode(app(SubsidiaryProfileCopyService::class)->canonical($command)));
            if ($hash !== $data['command_hash']) {
                return Http::response(['code' => 'command_hash_mismatch'], 409);
            }
            $existing = $this->site['items'][$key] ?? null;
            if ($existing && $existing['command_hash'] !== $hash) {
                return Http::response(['code' => 'command_conflict'], 409);
            }
            if (! $existing || $existing['state'] !== 'completed') {
                if ($key === 'fields') {
                    if (($command['expected_before'] ?? '') !== 'baseline' || $this->site['fields_digest'] !== 'baseline') {
                        return Http::response(['code' => 'target_changed', 'message' => 'target_changed'], 409);
                    }
                    $this->site['fields'] = $command['fields'];
                    $this->site['fields_digest'] = hash('sha256', json_encode($command['fields']));
                    $this->site['location'] = [(int) ($command['fields']['city_id'] ?? $command['fields']['region_id'] ?? 0)];
                    $existing = ['item_key' => $key, 'state' => 'completed', 'command_hash' => $hash, 'attachment_id' => null, 'result' => ['fields_digest' => $this->site['fields_digest']]];
                } else {
                    $file = $data['file'] ?? null;
                    if ($file !== hash('sha256', '') && $file !== null && hash('sha256', $file) !== $command['sha256']) {
                        return Http::response(['code' => 'checksum_mismatch'], 422);
                    }
                    abort_if($file === null && ! $existing, 422);
                    $id = $existing['attachment_id'] ?? $this->site['next_attachment']++;
                    $this->site['attachments'][$id] = ['id' => $id, 'menu_order' => $command['menu_order'], 'sha256' => $command['sha256'], 'copy_item' => $this->site['bound'].':'.$key, 'mime_type' => $command['mime_type'], 'alt_text' => $command['alt_text']];
                    if ($command['is_main']) {
                        $this->site['main'] = $id;
                    }
                    $existing = ['item_key' => $key, 'state' => 'completed', 'command_hash' => $hash, 'attachment_id' => $id, 'result' => ['attachment_id' => $id, 'sha256' => $command['sha256']]];
                }
                $this->site['items'][$key] = $existing;
            }
            if (in_array($key, $this->site['lose_response'], true)) {
                $this->site['lose_response'] = array_values(array_diff($this->site['lose_response'], [$key]));

                return Http::response('gateway timeout', 504);
            }

            return Http::response($existing);
        }
        if (preg_match('#^/clients/(\d+)/activate$#', $path, $m)) {
            $this->site['post_status'] = 'publish';
            $this->site['expire'] = now()->addDays((int) $r->data()['duration_days'])->timestamp;
            $this->site['activations'] = ($this->site['activations'] ?? 0) + 1;

            return Http::response(['success' => true, 'escort_expire' => $this->site['expire']]);
        }
        if (preg_match('#^/clients/(\d+)$#', $path, $m)) {
            return Http::response(['wp_post_id' => (int) $m[1], 'wp_user_id' => 20202, 'phone' => '255712345678', 'email' => 'devtest@customer.test', 'post_status' => $this->site['post_status'],
                'escort_expire' => $this->site['expire'], 'wp_profile_permalink' => 'https://target.example.test/escort/dev-test-pm/']);
        }

        return Http::response(['code' => 'unexpected', 'path' => $path], 404);
    }

    private function formData(HttpRequest $r): array
    {
        if ($r->isMultipart()) {
            $out = [];
            foreach ($r->data() as $part) {
                $out[$part['name']] = is_resource($part['contents']) ? stream_get_contents($part['contents'], -1, 0) : (string) $part['contents'];
            }

            return $out;
        }

        return $r->data();
    }

    private function resolverCreates(Platform $target, string $origin = 'created'): void
    {
        $resolver = \Mockery::mock(SubsidiaryClientResolver::class, [app(\App\Support\CrossPlatformPhoneResolver::class)])->makePartial();
        $resolver->shouldReceive('resolveFrozenOutcome')->andReturnUsing(function () use ($target, $origin) {
            $client = Client::factory()->create(['platform_id' => $target->id, 'name' => 'Dev Test PM', 'phone_normalized' => '255712345678', 'email' => 'devtest@customer.test', 'wp_post_id' => 10101, 'wp_user_id' => 20202, 'profile_status' => 'private', 'profile_reverted_at' => null, 'profile_removal_requested_at' => null]);

            return ['client' => $client, 'origin' => $origin];
        });
        $this->app->instance(SubsidiaryClientResolver::class, $resolver);
    }

    private function request(Deal $main, Platform $target, User $actor, array $extra = []): FreeTrialRequest
    {
        $key = (string) \Illuminate\Support\Str::uuid();
        $input = ['deal_id' => $main->id, 'platform_id' => $target->id, 'create_confirmed' => true, 'reason' => 'Paired market with the TZ subscription.', 'request_key' => $key] + $extra;
        $http = Request::create('/', 'POST');
        $http->setUserResolver(fn () => $actor);
        $terms = app(FreeTrialApprovalController::class)->terms($http, 'subsidiary', $input);
        $r = app(TrialApprovalService::class)->create($actor, $terms, null, $key);
        $main->update(['pending_subsidiary_trial' => ['status' => 'pending', 'platform_id' => $target->id, 'trial_request_id' => $r->id]]);

        return $r;
    }

    public function test_preview_shows_copy_categories_counts_mapped_location_and_purchased_days_without_writes(): void
    {
        [$source, $target, $main] = $this->fixture(14);
        $preview = $this->getJson('/api/crm/deals/'.$main->id.'/subsidiary-trial-preview?platform_id='.$target->id)->assertOk()->json();
        $this->assertSame('create_if_needed', $preview['resolution']['kind']);
        $this->assertEquals(['mode' => 'match_primary', 'days' => 14, 'purchased_days' => 14], array_intersect_key($preview['duration'], array_flip(['mode', 'days', 'purchased_days'])));
        $this->assertSame('ready', $preview['copy_preview']['status']);
        $this->assertSame([2, 1, true], [$preview['copy_preview']['photos'], $preview['copy_preview']['videos'], $preview['copy_preview']['main_included']]);
        $this->assertSame('Dar es Salaam → Airport', $preview['copy_preview']['location']['label']);
        $this->assertNotEmpty($preview['preview_token']);
        $this->assertDatabaseCount('free_trial_requests', 0);
        $this->assertDatabaseCount('subsidiary_profile_copy_runs', 0);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_ambiguous_location_requires_a_choice_before_a_token_is_issued(): void
    {
        [$source, $target, $main] = $this->fixture();
        $this->site['export']['location'] = ['region' => ['name' => 'Dar es Salaam', 'slug' => 'dar-es-salaam'], 'city' => ['name' => 'Mbezi', 'slug' => 'mbezi']];
        $preview = $this->getJson('/api/crm/deals/'.$main->id.'/subsidiary-trial-preview?platform_id='.$target->id)->assertOk()->json();
        $this->assertSame('needs_selection', $preview['copy_preview']['location']['status']);
        $this->assertNull($preview['preview_token']);
        $chosen = $this->getJson('/api/crm/deals/'.$main->id.'/subsidiary-trial-preview?platform_id='.$target->id.'&copy_region_id=40&copy_city_id=42')->assertOk()->json();
        $this->assertSame('selected', $chosen['copy_preview']['location']['status']);
        $this->assertNotEmpty($chosen['preview_token']);
    }

    public function test_period_resolution_follows_purchased_days_and_bounds(): void
    {
        [$source, $target, $main] = $this->fixture(30);
        $policies = app(SubsidiaryPromotionPolicyService::class);
        foreach ([14 => 14, 30 => 30, 180 => 180, 365 => 365] as $days => $expected) {
            ProductPrice::whereKey($main->product_price_id)->update(['duration_days' => $days]);
            $this->assertSame($expected, $policies->resolve($main->fresh(), $target, null, null, 7)['days']);
        }
        ProductPrice::whereKey($main->product_price_id)->update(['duration_days' => 366]);
        $this->assertSame('purchased_duration_unsupported', $policies->resolve($main->fresh(), $target, null, null, 7)['error']);
        $this->assertSame(45, $policies->resolve($main->fresh(), $target, 'custom', 45, 7)['days']);
        $this->assertSame('days_out_of_range', $policies->resolve($main->fresh(), $target, 'custom', 91, 7)['error']);
        ProductPrice::whereKey($main->product_price_id)->update(['duration_days' => 0]);
        $main->update(['duration_days' => null]);
        $this->assertSame('purchased_duration_missing', $policies->resolve($main->fresh(), $target, null, null, 7)['error']);
    }

    public function test_matched_180_day_purchase_is_allowed_only_on_the_versioned_contract(): void
    {
        [$source, $target, $main, $admin] = $this->fixture(180);
        $r = $this->request($main, $target, $admin);
        $this->assertSame(180, $r->terms_json['duration_days']);
        $this->assertSame('match_primary', $r->terms_json['promotion_duration_mode']);
        $legacy = $r->terms_json;
        unset($legacy['promotion_contract_version']);
        $this->assertSame(90, app(SubsidiaryPromotionPolicyService::class)->maxDays($legacy));
        $this->expectException(HttpException::class);
        $this->request($main, $target, $admin, ['duration_mode' => 'custom', 'duration_days' => 120]);
    }

    public function test_new_profile_receives_complete_verified_copy_before_one_paired_grant(): void
    {
        [$source, $target, $main, $admin] = $this->fixture(14);
        $this->resolverCreates($target);
        $r = $this->request($main, $target, $admin);
        $this->assertSame(1, $r->terms_json['copy_contract_version']);
        $run = SubsidiaryProfileCopyRun::where('request_id', $r->id)->firstOrFail();
        $this->assertSame(['fields', 'media-502', 'media-501', 'media-503'], $run->items()->pluck('item_key')->all());

        $out = app(TrialActivationService::class)->execute($r);

        $run->refresh();
        $this->assertSame('ready', $run->state);
        $this->assertSame('active', $out->fresh()->status);
        $this->assertSame(4, SubsidiaryProfileCopyItem::where('run_id', $run->id)->where('state', 'completed')->count());
        $this->assertCount(3, $this->site['attachments']);
        $this->assertSame('complete', $this->site['state']);
        $this->assertSame(1, $this->site['activations']);
        $this->assertSame(1, TrialActivationOperation::where('request_id', $r->id)->where('state', 'succeeded')->count());
        $this->assertSame(['name', 'content', 'whatsapp', 'instagram', 'rate1h_incall', 'services', 'region_id', 'city_id', 'currency'], array_keys($this->site['fields']));
        $this->assertSame('<p>Bio “Karibu” ✓</p>', $this->site['fields']['content']);
        $this->assertSame('255712345678', $this->site['fields']['whatsapp']);
        $this->assertSame('7', $this->site['fields']['currency']);
        $this->assertSame([41], $this->site['location']);
        $orders = collect($this->site['attachments'])->sortBy('menu_order')->pluck('sha256')->all();
        $this->assertSame([hash('sha256', 'photo-two-bytes'), hash('sha256', 'photo-one-bytes'), hash('sha256', 'video-bytes')], $orders);
        $this->assertSame(hash('sha256', 'photo-one-bytes'), $this->site['attachments'][$this->site['main']]['sha256']);
        $deal = Deal::findOrFail(TrialActivationOperation::where('request_id', $r->id)->value('deal_id'));
        $this->assertSame(14, (int) $deal->duration_days);
        $this->assertTrue($deal->expires_at->between(now()->addDays(14)->subMinutes(5), now()->addDays(14)->addMinutes(5)));
        $summary = $this->getJson('/api/crm/deals/'.$main->id.'/activation-summary')->assertOk()->json('subsidiary');
        $this->assertSame('active', $summary['status']);
        $this->assertSame('ready', $summary['copy']['state']);
        $this->assertSame('TZ', $summary['copied_from']);
    }

    public function test_activation_is_never_sent_before_copy_completes_and_lost_responses_complete_the_same_item(): void
    {
        [$source, $target, $main, $admin] = $this->fixture();
        $this->resolverCreates($target);
        $this->site['lose_response'] = ['media-501'];
        $r = $this->request($main, $target, $admin);
        app(TrialActivationService::class)->execute($r);
        $run = SubsidiaryProfileCopyRun::where('request_id', $r->id)->firstOrFail();
        $this->assertSame('ready', $run->state);
        $this->assertCount(3, $this->site['attachments']); // The resent command reused its attachment.
        // The lost response is reconciled by the inspect-only GET, not by a second upload.
        $this->assertSame(1, SubsidiaryProfileCopyItem::where('run_id', $run->id)->where('item_key', 'media-501')->value('attempts'));
        $calls = $this->site['calls'];
        $this->assertContains('GET /clients/10101/profile-copy/'.$run->id.'/media-501', $calls);
        $this->assertSame(1, count(array_filter($calls, fn ($c) => $c === 'POST /clients/10101/profile-copy/'.$run->id.'/media-501')));
        $activate = array_search('POST /clients/10101/activate', $calls, true);
        $complete = array_search('POST /clients/10101/profile-copy/'.$run->id.'/state', $calls, true);
        $this->assertNotFalse($activate);
        $this->assertGreaterThan($complete, $activate);
    }

    public function test_existing_profile_match_skips_copy_and_follows_existing_eligibility(): void
    {
        [$source, $target, $main, $admin] = $this->fixture();
        $this->resolverCreates($target, 'existing');
        $r = $this->request($main, $target, $admin);
        app(TrialActivationService::class)->execute($r);
        $this->assertSame('skipped', SubsidiaryProfileCopyRun::where('request_id', $r->id)->value('state'));
        $this->assertSame([], $this->site['items']);
        $this->assertNull($this->site['bound']);
    }

    public function test_target_edit_holds_setup_without_overwrite_or_grant_and_blocks_other_activation_paths(): void
    {
        [$source, $target, $main, $admin] = $this->fixture();
        $this->resolverCreates($target);
        $this->site['fields_digest'] = 'staff-edited';
        $r = $this->request($main, $target, $admin);
        app(TrialActivationService::class)->execute($r);
        $run = SubsidiaryProfileCopyRun::where('request_id', $r->id)->firstOrFail();
        $this->assertSame(['needs_review', 'target_changed'], [$run->state, $run->error_code]);
        $this->assertArrayNotHasKey('fields', $this->site);
        $this->assertSame(0, $this->site['activations'] ?? 0);
        $this->assertSame('setup_paused', $this->getJson('/api/crm/deals/'.$main->id.'/activation-summary')->json('subsidiary.status'));
        try {
            \App\Services\WpSyncService::forPlatform($target->id)->activateClient(10101, 'vip', 30);
            $this->fail('An incomplete copied profile was activated.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_cancellation_waits_for_unconfirmed_items_then_marks_target_abandoned(): void
    {
        [$source, $target, $main, $admin] = $this->fixture();
        $this->resolverCreates($target);
        $r = $this->request($main, $target, $admin);
        config(['subsidiary_trials.profile_copy_enabled' => false]);
        try {
            app(TrialActivationService::class)->execute($r);
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode()); // Disabled engine holds tagged work.
        }
        $run = SubsidiaryProfileCopyRun::where('request_id', $r->id)->firstOrFail();
        $this->assertNull($run->target_wp_post_id);
        $this->assertSame(0, $this->site['activations'] ?? 0);
        SubsidiaryProfileCopyItem::where('run_id', $run->id)->where('item_key', 'fields')->update(['state' => 'uncertain']);
        $this->postJson('/api/crm/free-trials/requests/'.$r->id.'/cancel', ['version' => $r->fresh()->version, 'reason' => 'Customer changed plans.'])->assertStatus(409);
        SubsidiaryProfileCopyItem::where('run_id', $run->id)->update(['state' => 'prepared']);
        $r->refresh()->update(['status' => 'approved']);
        $this->postJson('/api/crm/free-trials/requests/'.$r->id.'/cancel', ['version' => $r->fresh()->version, 'reason' => 'Customer changed plans.'])->assertStatus(202)->assertJsonPath('trial_request.status', 'canceled');
        $this->assertSame('cancelled', $run->fresh()->state);
        $this->assertNull($r->fresh()->active_identity);
    }

    public function test_expired_approval_holds_copy_keeps_reservation_and_admin_reauthorizes_same_run(): void
    {
        [$source, $target, $main, $admin] = $this->fixture();
        $this->resolverCreates($target);
        $r = $this->request($main, $target, $admin);
        config(['subsidiary_trials.profile_copy_enabled' => true]);
        $this->site['lose_response'] = [];
        // Bind and copy fields, then expire the approval before media.
        $copies = app(SubsidiaryProfileCopyService::class);
        app(\App\Services\SubsidiaryTrialWorkflow::class)->prepare($r->fresh());
        $r->refresh()->update(['expires_at' => now()->subMinute()]);
        $copies->gate($r->fresh());
        $run = SubsidiaryProfileCopyRun::where('request_id', $r->id)->firstOrFail();
        $this->assertSame(['needs_review', 'approval_expired'], [$run->state, $run->error_code]);
        $this->assertNotNull($r->fresh()->active_identity);
        // The legacy sweep leaves the bound copy alone.
        $r->update(['status' => 'approved']);
        $other = Client::factory()->create(['platform_id' => $target->id]);
        $http = Request::create('/', 'POST');
        $http->setUserResolver(fn () => $admin);
        app(TrialApprovalService::class)->create($admin, ['action' => 'activate', 'platform_id' => $target->id, 'client_ids' => [$other->id], 'product_id' => $target->product_id, 'duration_days' => 7, 'reason' => 'Unrelated request triggers the sweep.']);
        $this->assertSame('approved', $r->fresh()->status);
        $this->assertNotNull($r->fresh()->active_identity);
        $r->update(['status' => 'activating']);

        $this->postJson('/api/crm/free-trials/requests/'.$r->id.'/reauthorize-copy', ['request_version' => $r->fresh()->version, 'run_version' => $run->fresh()->version, 'reason' => 'Continue the same setup.'])->assertSuccessful();

        $this->assertSame('ready', $run->fresh()->state);
        $this->assertSame('active', $r->fresh()->status);
        $this->assertCount(3, $this->site['attachments']);
        $this->assertSame(1, $this->site['activations']);
    }

    public function test_cancelling_a_bound_setup_marks_the_private_target_abandoned_and_keeps_it_blocked(): void
    {
        [$source, $target, $main, $admin] = $this->fixture();
        $this->resolverCreates($target);
        $r = $this->request($main, $target, $admin);
        app(\App\Services\SubsidiaryTrialWorkflow::class)->prepare($r->fresh());
        $r->refresh()->update(['expires_at' => now()->subMinute()]);
        app(SubsidiaryProfileCopyService::class)->gate($r->fresh());
        $run = SubsidiaryProfileCopyRun::where('request_id', $r->id)->firstOrFail();
        $this->assertSame(10101, (int) $run->target_wp_post_id);
        $this->postJson('/api/crm/free-trials/requests/'.$r->id.'/cancel', ['version' => $r->fresh()->version, 'reason' => 'Customer declined the paired market.'])->assertStatus(202);
        $this->assertSame('canceled', $r->fresh()->status);
        $this->assertNull($r->fresh()->active_identity);
        $this->assertSame('cancelled', $run->fresh()->state);
        $this->assertSame('abandoned', $this->site['state']);
        $this->assertSame('private', $this->site['post_status']);
        $this->expectException(HttpException::class);
        \App\Services\WpSyncService::forPlatform($target->id)->activateClient(10101, 'vip', 30);
    }

    public function test_admin_resume_reconciles_the_held_step_and_finishes_the_same_run(): void
    {
        [$source, $target, $main, $admin] = $this->fixture();
        $this->resolverCreates($target);
        $this->site['fields_digest'] = 'staff-edited';
        $r = $this->request($main, $target, $admin);
        app(TrialActivationService::class)->execute($r);
        $run = SubsidiaryProfileCopyRun::where('request_id', $r->id)->firstOrFail();
        $summary = $this->getJson('/api/crm/deals/'.$main->id.'/activation-summary')->json('subsidiary.copy');
        $this->assertTrue($summary['can_resume']);
        $this->site['fields_digest'] = 'baseline'; // The edit was reviewed and reverted on the site.
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [$source->id, $target->id]]);
        Sanctum::actingAs($sales);
        $this->postJson('/api/crm/subsidiary-profile-copies/'.$run->id.'/resume', ['version' => $run->version, 'reason' => 'Checked.'])->assertStatus(403);
        Sanctum::actingAs($admin);
        $this->postJson('/api/crm/subsidiary-profile-copies/'.$run->id.'/resume', ['version' => $run->fresh()->version - 1, 'reason' => 'Checked.'])->assertStatus(409);
        $this->postJson('/api/crm/subsidiary-profile-copies/'.$run->id.'/resume', ['version' => $run->fresh()->version, 'reason' => 'Checked the reverted edit.'])->assertStatus(202);
        $this->assertSame('ready', $run->fresh()->state);
        $this->assertSame('active', $r->fresh()->status);
        $this->assertSame(1, $this->site['activations']);
    }

    public function test_pair_settings_are_versioned_scoped_and_audited(): void
    {
        [$source, $target] = $this->fixture();
        $url = '/api/crm/settings/subsidiary-promotions/'.$source->id.'/'.$target->id;
        $this->getJson($url)->assertOk()->assertJsonPath('duration_mode', 'match_primary')->assertJsonPath('copy_new_profile', true)->assertJsonPath('version', 0);
        $this->putJson($url, ['duration_mode' => 'fixed_days', 'fixed_days' => 21, 'allow_day_override' => false, 'copy_new_profile' => true, 'version' => 0])->assertOk()->assertJsonPath('fixed_days', 21)->assertJsonPath('version', 1);
        $this->putJson($url, ['duration_mode' => 'match_primary', 'allow_day_override' => true, 'copy_new_profile' => true, 'version' => 0])->assertStatus(409);
        $this->putJson('/api/crm/settings/subsidiary-promotions/'.$target->id.'/'.$source->id, ['duration_mode' => 'match_primary', 'allow_day_override' => true, 'copy_new_profile' => true, 'version' => 0])->assertStatus(422);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'subsidiary_promotion_policy', 'action' => 'subsidiary_promotion_policy_changed']);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [$source->id, $target->id]]);
        Sanctum::actingAs($sales);
        $this->putJson($url, ['duration_mode' => 'match_primary', 'allow_day_override' => true, 'copy_new_profile' => true, 'version' => 1])->assertStatus(403);
    }

    public function test_protected_or_removed_sources_are_not_offered(): void
    {
        [$source, $target, $main, $admin, $client] = $this->fixture();
        $client->update(['profile_removal_requested_at' => now()]);
        $preview = $this->getJson('/api/crm/deals/'.$main->id.'/subsidiary-trial-preview?platform_id='.$target->id)->assertOk()->json();
        $this->assertSame('unavailable', $preview['copy_preview']['status']);
        $this->assertNull($preview['preview_token']);
    }
}
