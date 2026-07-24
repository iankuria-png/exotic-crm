<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\RenewalCampaign;
use App\Models\Template;
use App\Models\User;
use App\Services\RenewalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RenewalCadenceTest extends TestCase
{
    use RefreshDatabase;

    private function renewalTemplate(?int $platformId = null, string $channel = 'sms'): Template
    {
        return Template::query()->create([
            'platform_id' => $platformId,
            'title' => 'Renewal ' . strtoupper($channel),
            'category' => 'renewal',
            'channel' => $channel,
            'subject' => null,
            'body' => 'Hi {{client_name}}, renew now.',
            'variables' => [],
            'status' => 'active',
            'is_quick_reply' => false,
        ]);
    }

    private function campaign(?int $platformId, int $triggerDays, int $templateId, string $channel = 'sms'): RenewalCampaign
    {
        return RenewalCampaign::query()->create([
            'platform_id' => $platformId,
            'trigger_days' => $triggerDays,
            'channel' => $channel,
            'template_id' => $templateId,
            'enabled' => true,
        ]);
    }

    private function deal(Platform $platform, int $expiresInDays, string $duration, ?int $durationDays): Deal
    {
        return Deal::factory()->create([
            'platform_id' => $platform->id,
            'status' => 'active',
            'duration' => $duration,
            'duration_days' => $durationDays,
            'activated_at' => now(),
            'expires_at' => now()->addDays($expiresInDays)->startOfDay()->addHours(9),
            'renewal_reminders_paused' => false,
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

    public function test_renewal_skips_client_who_already_renewed_to_a_later_subscription(): void
    {
        $platform = Platform::factory()->create();
        $template = $this->renewalTemplate();
        $campaign = $this->campaign(null, 3, $template->id); // +3 win-back, post-expiry

        // Client whose OLD deal matches the +3 window, but whose live subscription
        // (WP escort_expire) runs a month out — she has already renewed.
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'escort_expire' => now()->addMonth()->timestamp,
        ]);
        Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'expired',
            'duration' => 'biweekly',
            'duration_days' => 14,
            'activated_at' => now()->subDays(17),
            'expires_at' => now()->subDays(3)->startOfDay()->addHours(9),
            'renewal_reminders_paused' => false,
        ]);

        $result = app(RenewalService::class)->runCampaigns($campaign->id, null, [$platform->id], ['dry_run' => true]);
        $row = $result['campaigns'][0];

        $this->assertSame(1, $row['total_targeted']);
        $this->assertSame(1, $row['suppressed_count']);
        $this->assertSame('already_renewed', $row['targets_preview'][0]['suppressed_reason']);
    }

    public function test_guard_suppresses_reminder_whose_lead_time_reaches_cycle_length(): void
    {
        $platform = Platform::factory()->create(['renewal_reminder_guard_enabled' => true]);
        $template = $this->renewalTemplate();
        $campaign = $this->campaign(null, -7, $template->id); // 7 days before expiry

        $this->deal($platform, 7, 'weekly', 7); // weekly plan expiring in 7 days

        $result = app(RenewalService::class)->runCampaigns($campaign->id, null, [$platform->id], ['dry_run' => true]);
        $row = $result['campaigns'][0];

        $this->assertSame(1, $row['total_targeted']);
        $this->assertSame(1, $row['suppressed_count']);
        $this->assertTrue($row['targets_preview'][0]['suppressed']);
        $this->assertSame('short_cycle_guard', $row['targets_preview'][0]['suppressed_reason']);
    }

    public function test_guard_allows_reminder_shorter_than_cycle_length(): void
    {
        $platform = Platform::factory()->create(['renewal_reminder_guard_enabled' => true]);
        $template = $this->renewalTemplate();
        $campaign = $this->campaign(null, -3, $template->id); // 3 days before expiry

        $this->deal($platform, 3, 'weekly', 7); // weekly plan expiring in 3 days

        $result = app(RenewalService::class)->runCampaigns($campaign->id, null, [$platform->id], ['dry_run' => true]);
        $row = $result['campaigns'][0];

        $this->assertSame(1, $row['total_targeted']);
        $this->assertSame(0, $row['suppressed_count']);
        $this->assertFalse($row['targets_preview'][0]['suppressed']);
    }

    public function test_guard_can_be_disabled_per_market(): void
    {
        $platform = Platform::factory()->create(['renewal_reminder_guard_enabled' => false]);
        $template = $this->renewalTemplate();
        $campaign = $this->campaign(null, -7, $template->id);

        $this->deal($platform, 7, 'weekly', 7);

        $result = app(RenewalService::class)->runCampaigns($campaign->id, null, [$platform->id], ['dry_run' => true]);
        $row = $result['campaigns'][0];

        $this->assertSame(1, $row['total_targeted']);
        $this->assertSame(0, $row['suppressed_count'], 'Disabling the guard should let the reminder through.');
    }

    public function test_market_with_own_cadence_ignores_global_default(): void
    {
        $market = Platform::factory()->create();
        $globalTemplate = $this->renewalTemplate();
        $marketTemplate = $this->renewalTemplate($market->id);

        $this->campaign(null, -7, $globalTemplate->id);        // global default
        $this->campaign($market->id, -3, $marketTemplate->id); // market override

        // A deal that only the global -7 campaign would target
        $this->deal($market, 7, 'monthly', 30);
        // A deal the market's own -3 campaign targets
        $this->deal($market, 3, 'monthly', 30);

        $result = app(RenewalService::class)->runCampaigns(null, null, [$market->id], ['dry_run' => true]);

        $triggerDaysRun = collect($result['campaigns'])->pluck('trigger_days')->all();
        $this->assertSame([-3], $triggerDaysRun, 'Market with its own cadence should not run the global -7 campaign.');
        $this->assertSame(1, collect($result['campaigns'])->firstWhere('trigger_days', -3)['total_targeted']);
    }

    public function test_market_without_own_cadence_falls_back_to_global(): void
    {
        $marketWithOwn = Platform::factory()->create();
        $fallbackMarket = Platform::factory()->create();
        $globalTemplate = $this->renewalTemplate();
        $ownTemplate = $this->renewalTemplate($marketWithOwn->id);

        $this->campaign(null, -7, $globalTemplate->id);            // global default
        $this->campaign($marketWithOwn->id, -3, $ownTemplate->id); // unrelated market override

        $this->deal($fallbackMarket, 7, 'monthly', 30); // targeted by global -7

        $result = app(RenewalService::class)->runCampaigns(null, null, [$fallbackMarket->id], ['dry_run' => true]);

        $triggerDaysRun = collect($result['campaigns'])->pluck('trigger_days')->all();
        $this->assertSame([-7], $triggerDaysRun, 'Market without its own cadence should inherit the global default.');
        $this->assertSame(1, $result['campaigns'][0]['total_targeted']);
    }

    public function test_store_campaign_rejects_duplicate_offset(): void
    {
        $platform = Platform::factory()->create();
        $admin = $this->createUser('admin');
        Sanctum::actingAs($admin);
        $template = $this->renewalTemplate($platform->id);

        $first = $this->postJson('/api/crm/renewals/campaigns', [
            'platform_id' => $platform->id,
            'trigger_days' => -3,
            'template_id' => $template->id,
            'enabled' => true,
        ]);
        $first->assertCreated();

        $dup = $this->postJson('/api/crm/renewals/campaigns', [
            'platform_id' => $platform->id,
            'trigger_days' => -3,
            'template_id' => $template->id,
            'enabled' => true,
        ]);
        $dup->assertStatus(422);
    }

    public function test_guard_toggle_endpoint_persists_flag(): void
    {
        $platform = Platform::factory()->create(['renewal_reminder_guard_enabled' => true]);
        $admin = $this->createUser('admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/crm/renewals/guard', [
            'platform_id' => $platform->id,
            'enabled' => false,
        ])->assertOk()->assertJson(['guard_enabled' => false]);

        $this->assertFalse((bool) $platform->fresh()->renewal_reminder_guard_enabled);
    }

    public function test_seeder_ships_early_before_expiry_reminders_off_by_default(): void
    {
        $this->seed(\Database\Seeders\SprintThreeTemplateSeeder::class);

        $early = RenewalCampaign::query()
            ->whereNull('platform_id')->where('channel', 'sms')->whereIn('trigger_days', [-7, -3])->get();
        $this->assertCount(2, $early);
        $this->assertTrue($early->every(fn ($c) => $c->enabled === false), 'The -7 and -3 day reminders should seed disabled.');

        $rest = RenewalCampaign::query()
            ->whereNull('platform_id')->where('channel', 'sms')->whereIn('trigger_days', [0, 3])->get();
        $this->assertCount(2, $rest);
        $this->assertTrue($rest->every(fn ($c) => $c->enabled === true), 'The on-expiry and post-expiry reminders should stay enabled.');
    }

    public function test_cadence_endpoint_reports_override_state(): void
    {
        $platform = Platform::factory()->create();
        $admin = $this->createUser('admin');
        Sanctum::actingAs($admin);
        $template = $this->renewalTemplate($platform->id);
        $this->campaign($platform->id, -2, $template->id);

        $this->getJson('/api/crm/renewals/cadence?platform_id=' . $platform->id)
            ->assertOk()
            ->assertJson([
                'has_market_override' => true,
                'effective_source' => 'market',
                'guard_enabled' => true,
            ]);
    }
}
