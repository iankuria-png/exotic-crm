<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use App\Services\LifecycleSmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientProfilePauseTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Platform $platform;

    private array $remote;

    private int $expiry;

    private int $visibilityCalls = 0;

    private bool $pluginMissing = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->expiry = now()->addWeek()->timestamp;
        $this->platform = Platform::factory()->create([
            'wp_api_url' => 'https://pause.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'test', 'wp_api_password' => 'test',
        ]);
        $this->client = Client::factory()->create([
            'platform_id' => $this->platform->id,
            'profile_status' => 'publish',
            'lifecycle_state' => 'active',
            'needs_payment' => false,
            'notactive' => false,
            'escort_expire' => $this->expiry,
        ]);
        $this->remote = [
            'wp_post_id' => $this->client->wp_post_id,
            'post_status' => 'publish',
            'crm_lifecycle_state' => 'active',
            'escort_expire' => $this->expiry,
            'needs_payment' => false,
            'notactive' => false,
        ];

        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/clients/'.$this->client->wp_post_id.'/visibility')) {
                $this->visibilityCalls++;
                if ($this->pluginMissing) {
                    return Http::response(['code' => 'rest_no_route', 'message' => 'No route was found'], 404);
                }

                if ($request['action'] === 'pause') {
                    $this->remote = array_merge($this->remote, ['post_status' => 'private', 'notactive' => true]);
                } else {
                    $this->remote = array_merge($this->remote, [
                        'post_status' => $this->remote['needs_payment'] ? 'private' : 'publish',
                        'notactive' => false,
                    ]);
                }

                return Http::response([
                    'ok' => true,
                    'action' => $request['action'],
                    'post_status' => $this->remote['post_status'],
                    'notactive' => $this->remote['notactive'],
                    'needs_payment' => $this->remote['needs_payment'],
                ]);
            }

            if (str_ends_with($request->url(), '/clients/'.$this->client->wp_post_id)) {
                return Http::response($this->remote);
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

    private function pause(array $body = ['reason' => 'Companion travelling for two weeks'])
    {
        return $this->postJson('/api/crm/clients/'.$this->client->id.'/pause', $body);
    }

    private function resume()
    {
        return $this->postJson('/api/crm/clients/'.$this->client->id.'/resume');
    }

    public static function allowedRoles(): array
    {
        return [['sales'], ['sub_admin'], ['admin']];
    }

    #[DataProvider('allowedRoles')]
    public function test_staff_can_pause_a_public_profile_without_touching_the_plan(string $role): void
    {
        $this->actingRole($role);

        $this->pause()->assertOk()
            ->assertJsonPath('client.profile_status', 'private')
            ->assertJsonPath('client.notactive', true);

        $fresh = $this->client->fresh();
        $this->assertTrue($fresh->isProfilePaused());
        $this->assertSame($this->expiry, (int) $fresh->escort_expire);
        $this->assertDatabaseHas('timeline_events', ['entity_id' => $fresh->id, 'event_type' => 'profile_paused']);
        $this->assertDatabaseHas('audit_log', ['entity_id' => $fresh->id, 'action' => 'client_profile_pause']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/visibility') && $request['action'] === 'pause');
    }

    public function test_pausing_twice_does_not_call_wordpress_again(): void
    {
        $this->pause()->assertOk();
        $this->pause()->assertOk();

        $this->assertSame(1, $this->visibilityCalls);
    }

    public function test_resume_republishes_a_paused_profile(): void
    {
        $this->pause()->assertOk();

        $this->resume()->assertOk()
            ->assertJsonPath('client.profile_status', 'publish')
            ->assertJsonPath('client.notactive', false)
            ->assertJsonPath('client.profile_paused_at', null);

        $fresh = $this->client->fresh();
        $this->assertSame($this->expiry, (int) $fresh->escort_expire);
        $this->assertDatabaseHas('timeline_events', ['entity_id' => $fresh->id, 'event_type' => 'profile_resumed']);
        $this->assertDatabaseHas('audit_log', ['entity_id' => $fresh->id, 'action' => 'client_profile_resume']);
    }

    public function test_resume_keeps_a_lapsed_plan_hidden_until_payment(): void
    {
        $this->pause()->assertOk();
        $this->remote['needs_payment'] = true;

        $this->resume()->assertOk()
            ->assertJsonPath('client.profile_status', 'private')
            ->assertJsonPath('client.profile_paused_at', null)
            ->assertJsonFragment(['message' => 'Pause lifted. The plan lapsed while paused, so the profile stays hidden until payment.']);
    }

    public function test_pause_refuses_a_profile_that_is_not_public(): void
    {
        $this->client->update(['profile_status' => 'private', 'needs_payment' => true]);

        $this->pause()->assertStatus(409)->assertJsonPath('reason_code', 'not_public');
        $this->assertSame(0, $this->visibilityCalls);
    }

    public function test_resume_refuses_a_profile_awaiting_admin_activation(): void
    {
        $this->client->update(['profile_status' => 'private', 'notactive' => true]);

        $this->resume()->assertStatus(409)->assertJsonPath('reason_code', 'not_paused');
        $this->assertSame(0, $this->visibilityCalls);
    }

    public function test_resume_refuses_a_profile_with_a_removal_request(): void
    {
        $this->pause()->assertOk();
        $this->client->update(['profile_removal_requested_at' => now()]);

        $this->resume()->assertStatus(409)->assertJsonPath('reason_code', 'profile_removal_requested');
        $this->assertSame(1, $this->visibilityCalls);
    }

    public function test_market_without_the_updated_plugin_reports_it_and_changes_nothing(): void
    {
        $this->pluginMissing = true;

        $this->pause()->assertStatus(409)->assertJsonPath('reason_code', 'plugin_update_required');

        $fresh = $this->client->fresh();
        $this->assertSame('publish', $fresh->profile_status);
        $this->assertNull($fresh->profile_paused_at);
    }

    public static function forbiddenRoles(): array
    {
        return [['field_sales'], ['marketing']];
    }

    #[DataProvider('forbiddenRoles')]
    public function test_other_roles_cannot_pause_or_resume(string $role): void
    {
        $this->actingRole($role);

        $this->pause()->assertForbidden();
        $this->resume()->assertForbidden();
        $this->assertSame(0, $this->visibilityCalls);
    }

    public function test_sales_cannot_pause_outside_their_markets(): void
    {
        $this->actingRole('sales', accessible: false);

        $this->pause()->assertForbidden();
        $this->assertSame(0, $this->visibilityCalls);
    }

    public function test_paused_companions_are_not_sent_reactivation_messages(): void
    {
        $lapsedAt = now()->subDays(7)->setTime(12, 0)->timestamp;
        $this->client->update(['escort_expire' => $lapsedAt, 'phone_normalized' => '254711000001']);
        $other = Client::factory()->create([
            'platform_id' => $this->platform->id,
            'escort_expire' => $lapsedAt,
            'phone_normalized' => '254711000002',
        ]);

        $targets = fn () => app(LifecycleSmsService::class)
            ->reactivationTargets($this->platform->id, [7])
            ->pluck('id')
            ->all();

        $this->assertEqualsCanonicalizing([$this->client->id, $other->id], $targets());

        $this->client->update(['profile_status' => 'private', 'notactive' => true, 'profile_paused_at' => now()]);

        $this->assertSame([$other->id], $targets());
    }
}
