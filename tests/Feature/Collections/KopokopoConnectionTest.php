<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionProviderFeed;
use App\Models\Collections\CollectionProviderPoll;
use App\Models\Collections\CollectionReceipt;
use App\Services\Collections\Kopokopo\KopokopoConnectionService;
use App\Services\Collections\Kopokopo\KopokopoFeedService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class KopokopoConnectionTest extends KopokopoCollectionTestCase
{
    private function resultBody(CollectionProviderFeed $feed, CollectionProviderPoll $poll, array $rows = [], array $attributes = [], string $id = 'provider-poll-1'): string
    {
        return json_encode(['data' => ['id' => $id, 'type' => 'polling', 'attributes' => array_replace([
            'status' => 'Success', 'from_time' => $poll->from_at->toIso8601String(), 'to_time' => $poll->until_at->toIso8601String(),
            'scope' => 'Till', 'scope_reference' => '268491', 'transactions' => $rows, '_links' => [
                'self' => 'https://api.kopokopo.com/api/v2/polling/'.$id, 'callback_url' => app(KopokopoFeedService::class)->callback($feed, $poll)]], $attributes)]], JSON_THROW_ON_ERROR);
    }

    private function fakeRequests(?\Closure $post = null, ?\Closure $get = null): void
    {
        Http::fake(function ($request) use ($post, $get) {
            if ($request->url() === 'https://api.kopokopo.com/oauth/token') {
                return Http::response(['access_token' => 'fixture-access-token']);
            }
            if ($request->method() === 'POST' && $request->url() === 'https://api.kopokopo.com/api/v2/polling') {
                return $post ? $post($request) : Http::response([], 201, ['Location' => 'https://api.kopokopo.com/api/v2/polling/provider-poll-1']);
            }
            if ($request->method() === 'GET' && $get) {
                return $get($request);
            }
            throw new \RuntimeException('Unexpected fixture request');
        });
    }

    public function test_quiet_till_empty_poll_advances_verified_coverage_only_after_complete_result(): void
    {
        [$feed] = $this->kenya();
        $feed->update(['reconciled_until' => now()->subMinutes(30), 'last_poll_completed_at' => now()->subMinutes(30)]);
        $this->fakeRequests();
        $service = app(KopokopoConnectionService::class);
        $poll = $service->reconcile($feed);
        $this->assertSame('accepted', $poll->state);
        $this->assertFalse(app(KopokopoFeedService::class)->health($feed->fresh())['capture_ready']);
        $result = $service->result($feed, $poll, $this->resultBody($feed, $poll), true);
        $this->assertSame('complete', $result['status']);
        $this->assertTrue(app(KopokopoFeedService::class)->health($feed->fresh())['capture_ready']);
        $this->assertSame(0, CollectionReceipt::count());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/v2/polling') && $r['scope'] === 'till' && $r['scope_reference'] === '268491');
    }

    public function test_callback_before_post_response_waits_for_matching_request_identity(): void
    {
        [$feed] = $this->kenya();
        $this->fakeRequests(function () use ($feed) {
            $poll = CollectionProviderPoll::firstOrFail();
            $early = app(KopokopoConnectionService::class)->result($feed, $poll, $this->resultBody($feed, $poll), true);
            $this->assertSame('awaiting_request_identity', $early['status']);
            $this->assertSame('requesting', $poll->fresh()->state);

            return Http::response([], 201, ['Location' => 'https://api.kopokopo.com/api/v2/polling/provider-poll-1']);
        });
        $poll = app(KopokopoConnectionService::class)->reconcile($feed);
        $this->assertSame('complete', $poll->state);
        $this->assertNotNull($poll->pending_result);
    }

    public function test_timeout_is_not_blindly_posted_again(): void
    {
        [$feed] = $this->kenya();
        $posts = 0;
        $this->fakeRequests(function () use (&$posts) {
            $posts++;
            throw new ConnectionException('Fixture uncertain response');
        });
        $service = app(KopokopoConnectionService::class);
        $poll = $service->reconcile($feed);
        $this->assertSame('needs_review', $poll->state);
        $again = $service->reconcile($feed);
        $this->assertSame($poll->id, $again->id);
        $this->assertSame(1, $posts);
        $this->assertSame(1, CollectionProviderPoll::count());
    }

    public function test_unknown_post_can_be_recovered_by_authenticated_get_with_exact_callback_ownership(): void
    {
        [$feed] = $this->kenya();
        $this->fakeRequests(function () use ($feed) {
            $poll = CollectionProviderPoll::firstOrFail();
            app(KopokopoConnectionService::class)->result($feed, $poll, $this->resultBody($feed, $poll), true);
            throw new ConnectionException('Fixture response lost after callback');
        }, function () use ($feed) {
            return Http::response(json_decode($this->resultBody($feed, CollectionProviderPoll::firstOrFail()), true));
        });
        $service = app(KopokopoConnectionService::class);
        $poll = $service->reconcile($feed);
        $this->assertSame('needs_review', $poll->state);
        $this->assertNull($poll->provider_id);
        $this->assertSame('complete', $service->recover($feed, $poll)->state);
    }

    public function test_wrong_window_identity_or_foreign_till_never_completes_or_captures_a_prefix(): void
    {
        [$feed] = $this->kenya();
        $this->fakeRequests();
        $service = app(KopokopoConnectionService::class);
        $poll = $service->reconcile($feed);
        $cursor = $feed->fresh()->reconciled_until->toIso8601String();
        $badWindow = $this->resultBody($feed, $poll, [], ['to_time' => $poll->until_at->copy()->addMinute()->toIso8601String()]);
        $this->assertSame('quarantined', $service->result($feed, $poll, $badWindow, true)['status']);
        $resource = json_decode($this->providerBody(), true)['event'];
        $wrong = $resource;
        $wrong['resource']['till_number'] = 'K317087';
        $wrong['resource']['id'] = 'other-resource';
        $wrong['resource']['reference'] = 'UKXFIX0002';
        $this->assertSame('quarantined', $service->result($feed, $poll, $this->resultBody($feed, $poll, [$resource, $wrong]), true)['status']);
        $this->assertSame(0, CollectionReceipt::count());
        $this->assertSame($cursor, $feed->fresh()->reconciled_until->toIso8601String());
    }

    public function test_equivalent_get_and_callback_json_are_idempotent(): void
    {
        [$feed] = $this->kenya();
        $this->fakeRequests();
        $service = app(KopokopoConnectionService::class);
        $poll = $service->reconcile($feed);
        $row = json_decode($this->providerBody(), true)['event'];
        $body = $this->resultBody($feed, $poll, [$row]);
        $this->assertSame('complete', $service->result($feed, $poll, $body, true)['status']);
        $pretty = json_encode(json_decode($body, true), JSON_PRETTY_PRINT);
        $this->assertSame('duplicate', $service->result($feed, $poll->fresh(), $pretty, true)['status']);
        $this->assertSame(1, CollectionReceipt::count());
        $this->assertSame('complete', $poll->fresh()->state);
    }

    public function test_subscription_uses_v2_exact_till_and_never_reregisters_an_uncertain_result(): void
    {
        [$feed, , , $actor] = $this->kenya();
        $feed->update(['subscriptions' => []]);
        $posts = 0;
        Http::fake(function ($r) use (&$posts) {
            if (str_ends_with($r->url(), '/oauth/token')) {
                return Http::response(['access_token' => 'fixture-token']);
            }
            $posts++;
            $this->assertSame('https://api.kopokopo.com/api/v2/webhook_subscriptions', $r->url());
            $this->assertSame('268491', $r['scope_reference']);
            $this->assertSame('till', $r['scope']);
            $this->assertFalse($r['enable_daraja_payload']);
            if ($posts === 2) {
                throw new ConnectionException('Fixture ambiguous subscription response');
            }

            return Http::response([], 201, ['Location' => 'https://api.kopokopo.com/api/v2/webhook_subscriptions/provider-subscription-1']);
        });
        $service = app(KopokopoConnectionService::class);
        $result = $service->subscribe($feed, $actor, $feed->version);
        $this->assertSame('registered', data_get($result->subscriptions, 'buygoods_transaction_received.state'));
        $this->assertSame('needs_review', data_get($result->subscriptions, 'buygoods_transaction_reversed.state'));
        try {
            $service->subscribe($result, $actor, $result->version);
            $this->fail('Uncertain subscription was repeated');
        } catch (\App\Services\Collections\CollectionException $e) {
            $this->assertSame('provider_subscription_result_requires_review', $e->reason);
        }
        $this->assertSame(2, $posts);
    }

    public function test_provider_locations_reject_external_hosts_and_redirects(): void
    {
        [$feed] = $this->kenya();
        $this->fakeRequests(fn () => Http::response([], 201, ['Location' => 'https://example.org/api/v2/polling/provider-poll-1']));
        $poll = app(KopokopoConnectionService::class)->reconcile($feed);
        $this->assertSame('needs_review', $poll->state);
        $this->assertSame('provider_location_requires_review', $poll->last_error_code);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'example.org'));
    }
}
