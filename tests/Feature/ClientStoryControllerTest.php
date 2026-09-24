<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Platform;
use App\Models\TimelineEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Kyc\Concerns\InteractsWithKycFixtures;
use Tests\TestCase;

class ClientStoryControllerTest extends TestCase
{
    use InteractsWithKycFixtures;
    use RefreshDatabase;

    private const BASE = 'https://sync.example.test/wp-json/exotic-crm-sync/v1';

    private Platform $platform;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = $this->createPlatform([
            'wp_api_url' => self::BASE,
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $this->client = $this->createClientForPlatform($this->platform, ['wp_post_id' => 27955]);
    }

    public function test_index_returns_live_stories_and_management_permission(): void
    {
        Sanctum::actingAs($this->createKycUser('sales', [$this->platform->id]));
        Http::fake([self::BASE.'/clients/27955/stories' => Http::response([
            'enabled' => true,
            'state' => 'available',
            'stories' => [['id' => 97084, 'review_state' => 'approved']],
            'counts' => ['live' => 1, 'total' => 1],
        ])]);

        $this->getJson("/api/crm/clients/{$this->client->id}/stories")
            ->assertOk()
            ->assertJsonPath('stories.0.id', 97084)
            ->assertJsonPath('can_manage', true);
    }

    public function test_marketing_can_read_but_not_manage(): void
    {
        Sanctum::actingAs($this->createKycUser('marketing', [$this->platform->id]));
        Http::fake(['*' => Http::response(['enabled' => true, 'stories' => []])]);

        $this->getJson("/api/crm/clients/{$this->client->id}/stories")
            ->assertOk()
            ->assertJsonPath('can_manage', false);

        $this->postJson("/api/crm/clients/{$this->client->id}/stories/97084/moderate", ['action' => 'hide'])
            ->assertForbidden();
    }

    public function test_field_sales_cannot_moderate_or_pause_posting(): void
    {
        Sanctum::actingAs($this->createKycUser('field_sales', [$this->platform->id]));
        Http::fake(['*' => Http::response(['success' => true])]);

        $this->postJson("/api/crm/clients/{$this->client->id}/stories/97084/moderate", ['action' => 'delete'])
            ->assertForbidden();
        $this->postJson("/api/crm/clients/{$this->client->id}/stories/posting", ['blocked' => true, 'reason' => 'x'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_index_reports_an_outdated_plugin_as_unavailable(): void
    {
        Sanctum::actingAs($this->createKycUser('admin'));
        Http::fake(['*' => Http::response([
            'code' => 'rest_no_route',
            'message' => 'No route was found matching the URL and request method.',
        ], 404)]);

        $this->getJson("/api/crm/clients/{$this->client->id}/stories")
            ->assertOk()
            ->assertJsonPath('state', 'stories_unavailable')
            ->assertJsonPath('unavailable_reason', 'plugin_outdated');
    }

    public function test_moderation_calls_wordpress_and_records_timeline_and_audit(): void
    {
        $user = $this->createKycUser('sub_admin', [$this->platform->id]);
        Sanctum::actingAs($user);
        Http::fake([self::BASE.'/clients/27955/stories/97084/moderate' => Http::response([
            'success' => true,
            'story_id' => 97084,
            'action' => 'hide',
            'before' => 'unreviewed',
            'deleted' => false,
        ])]);

        $this->postJson("/api/crm/clients/{$this->client->id}/stories/97084/moderate", [
            'action' => 'hide',
            'reason' => 'Contact details in caption',
        ])->assertOk()->assertJsonPath('before', 'unreviewed');

        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST'
            && $request->url() === self::BASE.'/clients/27955/stories/97084/moderate'
            && $request['action'] === 'hide');

        $event = TimelineEvent::query()->where('entity_id', $this->client->id)->where('event_type', 'story_moderated')->sole();
        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame(97084, $event->content['story_id']);
        $this->assertSame('hide', $event->content['action']);
        $this->assertSame('Contact details in caption', $event->content['reason']);

        $audit = AuditLog::query()->where('action', 'client_story_moderate')->sole();
        $this->assertSame('Contact details in caption', $audit->reason);
    }

    public function test_moderation_rejects_unknown_actions_without_calling_wordpress(): void
    {
        Sanctum::actingAs($this->createKycUser('admin'));
        Http::fake();

        $this->postJson("/api/crm/clients/{$this->client->id}/stories/97084/moderate", ['action' => 'feature'])
            ->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_wordpress_ownership_refusal_passes_through_and_records_nothing(): void
    {
        Sanctum::actingAs($this->createKycUser('admin'));
        Http::fake(['*' => Http::response(['code' => 'story_not_found', 'message' => 'Story not found for this client.'], 404)]);

        $this->postJson("/api/crm/clients/{$this->client->id}/stories/97086/moderate", ['action' => 'delete'])
            ->assertNotFound()
            ->assertJsonPath('code', 'story_not_found');

        $this->assertSame(0, TimelineEvent::query()->where('event_type', 'story_moderated')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'client_story_moderate')->count());
    }

    public function test_wordpress_auth_failure_is_not_passed_through_as_a_crm_401(): void
    {
        Sanctum::actingAs($this->createKycUser('admin'));
        Http::fake(['*' => Http::response(['code' => 'rest_forbidden'], 401)]);

        $this->getJson("/api/crm/clients/{$this->client->id}/stories")->assertStatus(502);
    }

    public function test_pausing_posting_requires_a_reason(): void
    {
        Sanctum::actingAs($this->createKycUser('admin'));
        Http::fake();

        $this->postJson("/api/crm/clients/{$this->client->id}/stories/posting", ['blocked' => true, 'reason' => '  '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        Http::assertNothingSent();
    }

    public function test_pausing_and_resuming_posting_is_recorded(): void
    {
        $user = $this->createKycUser('admin');
        Sanctum::actingAs($user);
        Http::fake([self::BASE.'/clients/27955/stories/posting' => Http::sequence()
            ->push(['success' => true, 'posting_blocked' => true, 'was_blocked' => false, 'block_enforced' => true])
            ->push(['success' => true, 'posting_blocked' => false, 'was_blocked' => true, 'block_enforced' => true]),
        ]);

        $this->postJson("/api/crm/clients/{$this->client->id}/stories/posting", ['blocked' => true, 'reason' => 'Repeated nudity'])
            ->assertOk();
        $this->postJson("/api/crm/clients/{$this->client->id}/stories/posting", ['blocked' => false])
            ->assertOk();

        Http::assertSent(fn (HttpRequest $request) => $request['blocked'] === true
            && $request['reason'] === 'Repeated nudity'
            && $request['actor'] === $user->name);

        $this->assertSame(
            ['story_posting_blocked', 'story_posting_unblocked'],
            TimelineEvent::query()->where('entity_id', $this->client->id)->orderBy('id')->pluck('event_type')->all()
        );
        $this->assertSame(2, AuditLog::query()->where('action', 'client_story_posting_update')->count());
    }

    public function test_expire_ends_a_story_and_records_it(): void
    {
        Sanctum::actingAs($this->createKycUser('admin'));
        Http::fake([self::BASE.'/clients/27955/stories/97084/expire' => Http::response(['success' => true, 'action' => 'expire'])]);

        $this->postJson("/api/crm/clients/{$this->client->id}/stories/97084/expire")->assertOk();

        $this->assertSame(1, TimelineEvent::query()->where('event_type', 'story_expired')->count());
    }

    public function test_sales_cannot_reach_another_markets_client(): void
    {
        $other = $this->createPlatform(['wp_api_url' => self::BASE, 'wp_api_user' => 'u', 'wp_api_password' => 'p']);
        Sanctum::actingAs($this->createKycUser('sales', [$other->id]));
        Http::fake();

        $this->getJson("/api/crm/clients/{$this->client->id}/stories")->assertForbidden();
        $this->postJson("/api/crm/clients/{$this->client->id}/stories/97084/moderate", ['action' => 'hide'])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_unlinked_client_is_rejected(): void
    {
        Sanctum::actingAs($this->createKycUser('admin'));
        $client = $this->createClientForPlatform($this->platform, ['wp_post_id' => 0]);
        Http::fake();

        $this->getJson("/api/crm/clients/{$client->id}/stories")->assertUnprocessable();
    }
}
