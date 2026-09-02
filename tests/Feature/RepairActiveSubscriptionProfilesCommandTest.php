<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Platform;
use App\Support\ClientLifecycleState;
use App\Support\SubscriptionExpiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RepairActiveSubscriptionProfilesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_repairs_a_client_with_future_active_subscription_and_stale_lifecycle_state(): void
    {
        $platform = $this->createPlatform();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 70864,
            'name' => 'MWIZA',
            'profile_status' => 'publish',
            'lifecycle_state' => ClientLifecycleState::EXPIRED,
            'lifecycle_expired_at' => now()->subDays(5),
            'lifecycle_restored_at' => now()->subDay(),
            'needs_payment' => false,
            'notactive' => false,
            'premium' => true,
            'featured' => true,
            'escort_expire' => now()->subDay()->timestamp,
            'churned_at' => now()->subDay(),
            'churn_reason_code' => 'expired_unrenewed',
            'churn_source' => 'profile_inactive',
        ]);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'plan_type' => 'vip',
            'status' => 'active',
            'activated_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
        $baseUrl = rtrim($platform->wp_api_url, '/');

        Http::fake([
            "{$baseUrl}/clients/{$client->wp_post_id}/lifecycle" => Http::response(['ok' => true], 200),
        ]);

        $this->artisan('crm:repair-active-subscription-profiles', ['--client' => $client->id])
            ->expectsOutputToContain('DRY-RUN')
            ->expectsOutputToContain('would_repair')
            ->assertExitCode(0);

        $this->assertSame(ClientLifecycleState::EXPIRED, $client->fresh()->lifecycle_state);

        $this->artisan('crm:repair-active-subscription-profiles', ['--client' => $client->id, '--apply' => true])
            ->expectsOutputToContain('LIVE')
            ->expectsOutputToContain('repaired')
            ->assertExitCode(0);

        $fresh = $client->fresh();
        $this->assertSame('publish', $fresh->profile_status);
        $this->assertSame(ClientLifecycleState::ACTIVE, $fresh->lifecycle_state);
        $this->assertNull($fresh->lifecycle_expired_at);
        $this->assertNull($fresh->lifecycle_restored_at);
        $this->assertFalse((bool) $fresh->needs_payment);
        $this->assertFalse((bool) $fresh->notactive);
        // The repair publishes the deal's expiry rounded UP to market-local end of
        // day. Writing the raw stamp would strip end-of-day grace in both the CRM
        // and the theme, killing the profile partway through its final paid day.
        $expectedExpiry = SubscriptionExpiry::endOfDay($deal->expires_at->timestamp, 'Africa/Kampala');
        $this->assertSame($expectedExpiry, (int) $fresh->escort_expire);
        $this->assertGreaterThanOrEqual($deal->expires_at->timestamp, (int) $fresh->escort_expire);
        $this->assertTrue((bool) $fresh->premium);
        $this->assertTrue((bool) $fresh->featured);
        $this->assertNull($fresh->churned_at);
        Http::assertSent(function (ClientRequest $request) use ($baseUrl, $client, $deal): bool {
            return $request->url() === "{$baseUrl}/clients/{$client->wp_post_id}/lifecycle"
                && $request->method() === 'POST'
                && (string) $request['state'] === ClientLifecycleState::ACTIVE
                && (int) $request['escort_expire'] === SubscriptionExpiry::endOfDay($deal->expires_at->timestamp, 'Africa/Kampala')
                && (string) $request['product_type'] === 'vip'
                && (int) $request['crm_deal_id'] === (int) $deal->id;
        });

        $this->assertDatabaseHas('timeline_events', [
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'profile_subscription_state_repaired',
        ]);
    }

    public function test_command_does_not_repair_genuinely_expired_clients(): void
    {
        $platform = $this->createPlatform();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 70866,
            'profile_status' => 'publish',
            'lifecycle_state' => ClientLifecycleState::EXPIRED,
            'escort_expire' => now()->subDay()->timestamp,
        ]);
        Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'plan_type' => 'vip',
            'status' => 'expired',
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan('crm:repair-active-subscription-profiles', ['--client' => $client->id, '--apply' => true])
            ->expectsOutputToContain('Found 0 affected profile(s).')
            ->assertExitCode(0);

        $this->assertSame(ClientLifecycleState::EXPIRED, $client->fresh()->lifecycle_state);
    }

    private function createPlatform(): Platform
    {
        return Platform::query()->create([
            'name' => 'Uganda Market',
            'domain' => 'ug-'.Str::random(6).'.example.test',
            'country' => 'Uganda',
            'timezone' => 'Africa/Kampala',
            'phone_prefix' => '256',
            'currency_code' => 'UGX',
            'is_active' => true,
            'lifecycle_policy_enabled' => true,
            'wp_api_url' => 'https://ug.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }
}
