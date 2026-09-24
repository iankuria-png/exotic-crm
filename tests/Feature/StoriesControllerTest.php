<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Platform;
use App\Models\TimelineEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Kyc\Concerns\InteractsWithKycFixtures;
use Tests\TestCase;

class StoriesControllerTest extends TestCase
{
    use InteractsWithKycFixtures;
    use RefreshDatabase;

    private const BASE = 'https://sync.example.test/wp-json/exotic-crm-sync/v1';

    private Platform $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = $this->createPlatform([
            'wp_api_url' => self::BASE,
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    public function test_markets_lists_only_the_users_markets(): void
    {
        $other = $this->createPlatform(['wp_api_url' => self::BASE, 'wp_api_user' => 'u', 'wp_api_password' => 'p']);
        Sanctum::actingAs($this->createKycUser('sales', [$this->platform->id]));

        $ids = collect($this->getJson('/api/crm/stories/markets')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertContains($this->platform->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_overview_reports_role_abilities(): void
    {
        Sanctum::actingAs($this->createKycUser('sub_admin', [$this->platform->id]));
        Http::fake([self::BASE.'/stories/overview' => Http::response(['available' => true, 'enabled' => true, 'stats' => ['live' => 3]])]);

        $this->getJson("/api/crm/stories/{$this->platform->id}/overview")
            ->assertOk()
            ->assertJsonPath('stats.live', 3)
            ->assertJsonPath('abilities.moderate', true)
            ->assertJsonPath('abilities.brand', true)
            ->assertJsonPath('abilities.settings', false)
            ->assertJsonPath('abilities.reward', false);
    }

    public function test_overview_on_an_outdated_plugin_is_unavailable_not_an_error(): void
    {
        Sanctum::actingAs($this->createKycUser('admin'));
        Http::fake(['*' => Http::response(['code' => 'rest_no_route'], 404)]);

        $this->getJson("/api/crm/stories/{$this->platform->id}/overview")
            ->assertOk()
            ->assertJsonPath('unavailable_reason', 'plugin_outdated');
    }

    public function test_list_links_stories_to_crm_clients(): void
    {
        Sanctum::actingAs($this->createKycUser('sales', [$this->platform->id]));
        $client = $this->createClientForPlatform($this->platform, ['wp_post_id' => 27955]);
        Http::fake([self::BASE.'/stories/list*' => Http::response([
            'stories' => [
                ['id' => 1, 'profile' => ['post_id' => 27955, 'title' => 'Mary']],
                ['id' => 2, 'profile' => ['post_id' => 99999, 'title' => 'Unknown']],
            ],
        ])]);

        $this->getJson("/api/crm/stories/{$this->platform->id}/stories?state=unreviewed")
            ->assertOk()
            ->assertJsonPath('stories.0.client_id', $client->id)
            ->assertJsonPath('stories.1.client_id', null);

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'state=unreviewed'));
    }

    public function test_bulk_moderation_writes_each_clients_timeline_and_one_audit_row(): void
    {
        $user = $this->createKycUser('sales', [$this->platform->id]);
        Sanctum::actingAs($user);
        $client = $this->createClientForPlatform($this->platform, ['wp_post_id' => 27955]);
        Http::fake([self::BASE.'/stories/moderate' => Http::response([
            'success' => true,
            'action' => 'hide',
            'done' => 2,
            'results' => [
                ['story_id' => 11, 'ok' => true, 'before' => 'unreviewed', 'profile_post_id' => 27955],
                ['story_id' => 12, 'ok' => true, 'before' => 'approved', 'profile_post_id' => 88888],
                ['story_id' => 13, 'ok' => false, 'error' => 'story_not_found'],
            ],
        ])]);

        $this->postJson("/api/crm/stories/{$this->platform->id}/moderate", [
            'action' => 'hide',
            'story_ids' => [11, 12, 13],
            'reason' => 'Contact details on screen',
        ])->assertOk()->assertJsonPath('done', 2);

        Http::assertSent(fn (HttpRequest $request) => $request['action'] === 'hide' && $request['story_ids'] === [11, 12, 13]);

        $event = TimelineEvent::query()->where('entity_id', $client->id)->sole();
        $this->assertSame('story_moderated', $event->event_type);
        $this->assertSame(11, $event->content['story_id']);
        $this->assertSame('stories_page', $event->content['source']);

        $audit = AuditLog::query()->where('action', 'stories_moderate')->sole();
        $this->assertSame('Contact details on screen', $audit->reason);
    }

    public function test_field_sales_and_marketing_cannot_open_the_page(): void
    {
        Http::fake();
        foreach (['field_sales', 'marketing'] as $role) {
            Sanctum::actingAs($this->createKycUser($role, [$this->platform->id]));
            $this->getJson("/api/crm/stories/{$this->platform->id}/overview")->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_settings_and_rewards_are_admin_only(): void
    {
        Sanctum::actingAs($this->createKycUser('sub_admin', [$this->platform->id]));
        Http::fake();

        $this->postJson("/api/crm/stories/{$this->platform->id}/settings", ['max_active' => 5])->assertForbidden();
        $this->postJson("/api/crm/stories/{$this->platform->id}/hottest/award", ['week' => '2026-W38'])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_sales_cannot_post_brand_stories(): void
    {
        Sanctum::actingAs($this->createKycUser('sales', [$this->platform->id]));
        Http::fake();

        $this->postJson("/api/crm/stories/{$this->platform->id}/brand", ['kind' => 'announcement', 'headline' => 'Hi'])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_settings_save_audits_only_changed_values(): void
    {
        Sanctum::actingAs($this->createKycUser('admin'));
        Http::fake([self::BASE.'/stories/settings' => Http::response([
            'success' => true,
            'before' => ['max_active' => 10, 'clip_seconds' => 15, 'enabled' => true],
            'settings' => ['max_active' => 12, 'clip_seconds' => 15, 'enabled' => true],
        ])]);

        $this->postJson("/api/crm/stories/{$this->platform->id}/settings", ['max_active' => 12, 'clip_seconds' => 15])->assertOk();

        $audit = AuditLog::query()->where('action', 'stories_settings_update')->sole();
        $this->assertSame(['max_active' => 12], $audit->after_state);
        $this->assertSame(['max_active' => 10], $audit->before_state);
    }

    public function test_settings_reject_values_wp_admin_does_not_offer(): void
    {
        Sanctum::actingAs($this->createKycUser('admin'));
        Http::fake();

        $this->postJson("/api/crm/stories/{$this->platform->id}/settings", ['clip_seconds' => 20])->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_brand_poll_needs_two_options_and_posts_with_media(): void
    {
        Sanctum::actingAs($this->createKycUser('sub_admin', [$this->platform->id]));
        Http::fake(['*' => Http::response(['success' => true, 'story_id' => 501])]);

        $this->postJson("/api/crm/stories/{$this->platform->id}/brand", [
            'kind' => 'poll', 'headline' => 'Which city next?', 'options' => ['Nairobi', ''],
        ])->assertUnprocessable()->assertJsonValidationErrors('options');
        Http::assertNothingSent();

        $this->post("/api/crm/stories/{$this->platform->id}/brand", [
            'kind' => 'advert',
            'headline' => 'Verified profiles get more calls',
            'cta_label' => 'Get verified',
            'cta_url' => 'https://exotic-online.com/verify',
            'days' => 3,
            'file' => UploadedFile::fake()->image('advert.jpg', 1080, 1920),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('story_id', 501);

        Http::assertSent(fn (HttpRequest $request) => $request->isMultipart()
            && str_ends_with($request->url(), '/stories/brand'));
        $this->assertSame(1, AuditLog::query()->where('action', 'stories_brand_create')->count());
    }

    public function test_revoke_requires_a_reason_and_is_audited(): void
    {
        Sanctum::actingAs($this->createKycUser('admin'));
        Http::fake(['*' => Http::response(['success' => true, 'week' => '2026-W38', 'profile_post_id' => 27955])]);

        $this->postJson("/api/crm/stories/{$this->platform->id}/hottest/revoke", ['week' => '2026-W38'])
            ->assertUnprocessable();

        $this->postJson("/api/crm/stories/{$this->platform->id}/hottest/revoke", ['week' => '2026-W38', 'reason' => 'Likes were bought'])
            ->assertOk();

        $this->assertSame('Likes were bought', AuditLog::query()->where('action', 'stories_reward_revoke')->sole()->reason);
    }

    public function test_sales_cannot_open_another_market(): void
    {
        $other = $this->createPlatform(['wp_api_url' => self::BASE, 'wp_api_user' => 'u', 'wp_api_password' => 'p']);
        Sanctum::actingAs($this->createKycUser('sales', [$this->platform->id]));
        Http::fake();

        $this->getJson("/api/crm/stories/{$other->id}/overview")->assertForbidden();
        Http::assertNothingSent();
    }
}
