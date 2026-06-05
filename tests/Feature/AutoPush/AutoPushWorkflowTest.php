<?php

namespace Tests\Feature\AutoPush;

use App\Console\Commands\RunAutoPushEngine;
use App\Jobs\SendPushNotificationJob;
use App\Models\AutoPushAlert;
use App\Models\AutoPushPlan;
use App\Models\AutoPushRun;
use App\Models\Client;
use App\Models\ClientRetentionInsight;
use App\Models\Platform;
use App\Models\PushCampaign;
use App\Models\PushCampaignItem;
use App\Models\User;
use App\Services\AutoPush\AutoPushEngineService;
use App\Services\AutoPush\AutoPushMaintenanceService;
use App\Services\AutoPush\AutoPushSelectionService;
use App\Services\PushNotification\PushProviderService;
use App\Support\AutoPushSlotAllocator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AutoPushWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_selection_service_returns_recent_new_subscription_clients(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $clients = Client::factory()->count(3)->create([
            'platform_id' => $platform->id,
            'client_type' => 'escort',
        ]);

        foreach ($clients as $index => $client) {
            \App\Models\Deal::factory()->create([
                'platform_id' => $platform->id,
                'client_id' => $client->id,
                'subscription_lifecycle' => 'new',
                'status' => 'active',
                'activated_at' => now()->subHours($index + 1),
            ]);
        }

        $plan = $this->makePlan($platform, [
            'buckets' => [[
                'type' => 'new_subscriptions',
                'enabled' => true,
                'limit' => 2,
                'params' => ['lookback_hours' => 48, 'lifecycle' => ['new', 'renewal']],
            ]],
        ]);

        $result = app(AutoPushSelectionService::class)->selectNewSubscriptions($plan);

        $this->assertCount(2, $result);
        $this->assertSame($clients[0]->id, $result->first()->id);
    }

    public function test_slot_allocator_respects_same_day_and_next_active_day_spillover(): void
    {
        Carbon::setTestNow('2026-06-05 06:00:00');

        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $plan = $this->makePlan($platform, [
            'schedule' => array_merge($this->defaultSchedule(), [
                'active_days' => [5, 6],
                'window_start' => '10:00',
                'window_end' => '12:00',
                'interval_hours' => 2,
                'max_items_per_day' => 2,
                'lookahead_days' => 2,
            ]),
            'reliability' => array_merge($this->defaultReliability(), [
                'replacement_spillover' => 'same_day',
            ]),
        ]);

        $campaign = PushCampaign::query()->create([
            'name' => 'Auto Push',
            'platform_id' => $platform->id,
            'status' => 'running',
        ]);

        $after = Carbon::parse('2026-06-05 10:00:00', 'Africa/Nairobi')->utc();
        $occupied = [
            Carbon::parse('2026-06-05 12:00:00', 'Africa/Nairobi')->utc(),
        ];

        $sameDay = AutoPushSlotAllocator::nextFreeSlot($plan, $campaign, $after, $occupied);
        $this->assertNull($sameDay);

        $plan->forceFill([
            'reliability' => array_merge($this->defaultReliability(), [
                'replacement_spillover' => 'next_active_day',
            ]),
        ])->save();

        $nextDay = AutoPushSlotAllocator::nextFreeSlot($plan->fresh('platform'), $campaign, $after, $occupied);
        $this->assertNotNull($nextDay);
        $this->assertSame(
            '2026-06-06 10:00',
            $nextDay->copy()->setTimezone('Africa/Nairobi')->format('Y-m-d H:i')
        );
    }

    public function test_engine_run_plan_creates_draft_campaign_and_alert_when_autopilot_is_disabled(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'client_type' => 'escort',
        ]);
        \App\Models\Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'subscription_lifecycle' => 'new',
            'activated_at' => now()->subHour(),
            'status' => 'active',
        ]);
        $reserveClient = Client::factory()->create([
            'platform_id' => $platform->id,
            'client_type' => 'escort',
        ]);
        \App\Models\Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $reserveClient->id,
            'subscription_lifecycle' => 'new',
            'activated_at' => now()->subHours(2),
            'status' => 'active',
        ]);

        $plan = $this->makePlan($platform, [
            'autopilot' => false,
            'buckets' => [[
                'type' => 'new_subscriptions',
                'enabled' => true,
                'limit' => 2,
                'params' => ['lookback_hours' => 24, 'lifecycle' => ['new']],
            ]],
            'schedule' => array_merge($this->defaultSchedule(), [
                'max_items_per_day' => 1,
            ]),
        ]);

        $run = app(AutoPushEngineService::class)->runPlan($plan);

        $this->assertSame('completed', $run->status);
        $campaign = PushCampaign::query()->findOrFail($run->campaign_id);
        $this->assertSame('draft', $campaign->status);
        $this->assertNotEmpty($run->reserve_client_ids ?? []);
        $this->assertDatabaseHas('auto_push_alerts', [
            'auto_push_plan_id' => $plan->id,
            'campaign_id' => $campaign->id,
            'type' => 'awaiting_approval',
        ]);
    }

    public function test_send_push_job_persists_provider_meta(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $campaign = PushCampaign::query()->create([
            'name' => 'Meta Campaign',
            'platform_id' => $platform->id,
            'status' => 'running',
            'created_by' => null,
        ]);
        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://kenya.example/?p=10',
            'custom_message' => 'Hello there',
            'status' => 'pending',
        ]);

        $provider = Mockery::mock(PushProviderService::class);
        $provider->shouldReceive('sendPush')->once()->andReturn([
            'success' => true,
            'provider' => 'wonderpush',
            'provider_notification_id' => 'np-123',
            'fallback_attempted' => true,
            'fallback_from' => 'webpushr',
        ]);
        $audit = Mockery::mock(\App\Services\AuditService::class);
        $audit->shouldReceive('record')->andReturnNull();

        $job = new SendPushNotificationJob($item->id);
        $job->handle($provider, $audit);

        $item->refresh();
        $this->assertSame('wonderpush', data_get($item->provider_meta, 'provider'));
        $this->assertTrue((bool) data_get($item->provider_meta, 'fallback_attempted'));
        $this->assertSame('webpushr', data_get($item->provider_meta, 'fallback_from'));
        $this->assertSame('sent', $item->status);
    }

    public function test_maintenance_service_replaces_failed_item_and_revives_partial_campaign(): void
    {
        Carbon::setTestNow('2026-06-05 06:00:00');
        Queue::fake();

        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $primaryClient = Client::factory()->create(['platform_id' => $platform->id]);
        $reserveClient = Client::factory()->create(['platform_id' => $platform->id]);
        $plan = $this->makePlan($platform, [
            'reliability' => array_merge($this->defaultReliability(), [
                'replacement_spillover' => 'next_active_day',
                'max_replacements_per_item' => 2,
            ]),
        ]);
        $run = AutoPushRun::query()->create([
            'auto_push_plan_id' => $plan->id,
            'platform_id' => $platform->id,
            'status' => 'completed',
            'reserve_count' => 1,
            'reserve_client_ids' => [$reserveClient->id],
        ]);
        $campaign = PushCampaign::query()->create([
            'name' => 'Partial Campaign',
            'platform_id' => $platform->id,
            'status' => 'partial',
            'auto_push_plan_id' => $plan->id,
            'auto_push_run_id' => $run->id,
            'completed_at' => now(),
            'sent_count' => 1,
            'failed_count' => 1,
        ]);
        $failedItem = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'client_id' => $primaryClient->id,
            'profile_url' => 'https://kenya.example/?p=1',
            'custom_message' => 'Failed',
            'scheduled_at' => Carbon::parse('2026-06-05 10:00:00', 'Africa/Nairobi')->utc(),
            'status' => 'failed',
            'replacement_round' => 0,
        ]);

        $made = app(AutoPushMaintenanceService::class)->replaceFailedItemsForCampaign($campaign);

        $this->assertSame(1, $made);
        $campaign->refresh();
        $run->refresh();
        $replacement = PushCampaignItem::query()
            ->where('campaign_id', $campaign->id)
            ->where('replaces_item_id', $failedItem->id)
            ->first();

        $this->assertNotNull($replacement);
        $this->assertSame('running', $campaign->status);
        $this->assertNull($campaign->completed_at);
        $this->assertSame(1, (int) $replacement->replacement_round);
        $this->assertSame(0, count($run->reserve_client_ids ?? []));
    }

    public function test_marketing_user_can_manage_auto_push_plans_and_preview(): void
    {
        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $user = $this->createUser('marketing', [$platform->id]);
        $client = Client::factory()->create(['platform_id' => $platform->id]);
        \App\Models\Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'subscription_lifecycle' => 'new',
            'activated_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($user);

        $store = $this->postJson('/api/crm/auto-push/plans', $this->planRequestPayload($platform->id));
        $store->assertCreated();
        $planId = (int) $store->json('plan.id');

        $this->getJson('/api/crm/auto-push/plans')
            ->assertOk()
            ->assertJsonPath('data.0.id', $planId);

        $this->postJson("/api/crm/auto-push/plans/{$planId}/preview")
            ->assertOk()
            ->assertJsonPath('selection.primary_count', 1)
            ->assertJsonCount(1, 'items');
    }

    public function test_run_auto_push_command_skips_not_due_plans_and_runs_due_plans(): void
    {
        Carbon::setTestNow('2026-06-05 08:00:00');

        $platform = $this->createPlatform('Kenya', 'kenya.example', 'Kenya');
        $dueClient = Client::factory()->create(['platform_id' => $platform->id]);
        \App\Models\Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $dueClient->id,
            'subscription_lifecycle' => 'new',
            'activated_at' => now()->subHour(),
        ]);

        $duePlan = $this->makePlan($platform, [
            'name' => 'Due plan',
            'buckets' => [[
                'type' => 'new_subscriptions',
                'enabled' => true,
                'limit' => 1,
                'params' => ['lookback_hours' => 24, 'lifecycle' => ['new']],
            ]],
        ]);
        $coveredPlan = $this->makePlan($platform, [
            'name' => 'Covered plan',
            'buckets' => [[
                'type' => 'new_subscriptions',
                'enabled' => true,
                'limit' => 1,
                'params' => ['lookback_hours' => 24, 'lifecycle' => ['new']],
            ]],
            'schedule' => array_merge($this->defaultSchedule(), [
                'runway_threshold' => 1,
            ]),
        ]);
        $coveredCampaign = PushCampaign::query()->create([
            'name' => 'Existing Draft',
            'platform_id' => $platform->id,
            'status' => 'draft',
            'auto_push_plan_id' => $coveredPlan->id,
        ]);
        PushCampaignItem::query()->create([
            'campaign_id' => $coveredCampaign->id,
            'profile_url' => 'https://kenya.example/?p=2',
            'custom_message' => 'Covered',
            'scheduled_at' => now()->addHours(2),
            'status' => 'pending',
        ]);

        $this->artisan('crm:run-auto-push')
            ->expectsOutputToContain('Due plan')
            ->expectsOutputToContain('Covered plan')
            ->assertExitCode(0);

        $this->assertDatabaseCount('auto_push_runs', 1);
        $this->assertDatabaseHas('auto_push_plans', [
            'id' => $duePlan->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    private function makePlan(Platform $platform, array $overrides = []): AutoPushPlan
    {
        return AutoPushPlan::query()->create(array_merge([
            'name' => 'Kenya Auto Push',
            'platform_id' => $platform->id,
            'enabled' => true,
            'autopilot' => false,
            'buckets' => [[
                'type' => 'new_subscriptions',
                'enabled' => true,
                'limit' => 2,
                'params' => ['lookback_hours' => 48, 'lifecycle' => ['new', 'renewal']],
            ]],
            'schedule' => $this->defaultSchedule(),
            'message_strategy' => $this->defaultMessageStrategy(),
            'reliability' => $this->defaultReliability(),
        ], $overrides));
    }

    private function defaultSchedule(): array
    {
        return [
            'active_days' => [1, 2, 3, 4, 5, 6, 7],
            'window_start' => '10:00',
            'window_end' => '16:00',
            'interval_hours' => 2,
            'max_items_per_day' => 4,
            'lookahead_days' => 1,
            'count_unapproved_drafts_as_coverage' => true,
        ];
    }

    private function defaultMessageStrategy(): array
    {
        return [
            'mode' => 'seed',
            'seed_phrases' => ['{{name}} is live in {{city}} tonight.'],
            'tone' => 'playful',
            'temperament' => 'light',
            'language' => 'en',
            'max_chars' => 120,
        ];
    }

    private function defaultReliability(): array
    {
        return [
            'reserve_multiplier' => 1.5,
            'max_replacements_per_item' => 2,
            'exclude_pushed_within_days' => 0,
            'replacement_spillover' => 'next_active_day',
            'sms_alerts_enabled' => false,
        ];
    }

    private function planRequestPayload(int $platformId): array
    {
        return [
            'name' => 'Kenya Autopilot',
            'platform_id' => $platformId,
            'enabled' => true,
            'autopilot' => false,
            'buckets' => [[
                'type' => 'new_subscriptions',
                'enabled' => true,
                'limit' => 1,
                'params' => ['lookback_hours' => 24, 'lifecycle' => ['new']],
            ]],
            'schedule' => $this->defaultSchedule(),
            'message_strategy' => $this->defaultMessageStrategy(),
            'reliability' => $this->defaultReliability(),
        ];
    }

    private function createPlatform(string $name, string $domain, string $country, string $timezone = 'Africa/Nairobi'): Platform
    {
        return Platform::query()->create([
            'name' => $name,
            'domain' => $domain,
            'country' => $country,
            'timezone' => $timezone,
            'wp_api_url' => 'https://' . $domain . '/wp-json/wp/v2',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
        ]);
    }

    private function createUser(string $role, array $assignedMarketIds = []): User
    {
        return User::query()->create([
            'name' => ucfirst($role) . ' User',
            'email' => strtolower($role) . Str::random(6) . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'assigned_market_ids' => $assignedMarketIds,
            'status' => 'active',
        ]);
    }
}
