<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Platform;
use App\Services\SubscriptionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubscriptionLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_defaults_to_new_when_client_has_no_prior_subscription_history(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'profile_status' => 'publish',
            'premium' => false,
            'featured' => false,
            'premium_expire' => null,
            'featured_expire' => null,
            'escort_expire' => null,
        ]);

        $resolved = app(SubscriptionLifecycleService::class)->resolveForClient($client, (int) $platform->id);

        $this->assertSame(SubscriptionLifecycleService::LIFECYCLE_NEW, $resolved['subscription_lifecycle']);
        $this->assertSame(SubscriptionLifecycleService::SOURCE_PREDICTED, $resolved['subscription_lifecycle_source']);
        $this->assertFalse($resolved['operator_overridden']);
    }

    public function test_it_defaults_to_renewal_when_client_has_prior_tracked_subscription_history(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
        ]);

        Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'expired',
            'activated_at' => now()->subMonths(2),
            'expires_at' => now()->subMonth(),
        ]);

        $resolved = app(SubscriptionLifecycleService::class)->resolveForClient($client, (int) $platform->id);

        $this->assertSame(SubscriptionLifecycleService::LIFECYCLE_RENEWAL, $resolved['subscription_lifecycle']);
    }

    public function test_it_defaults_to_renewal_when_client_has_legacy_subscription_signals(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'premium_expire' => now()->subDays(10)->timestamp,
        ]);

        $resolved = app(SubscriptionLifecycleService::class)->resolveForClient($client, (int) $platform->id);

        $this->assertSame(SubscriptionLifecycleService::LIFECYCLE_RENEWAL, $resolved['subscription_lifecycle']);
    }

    public function test_it_requires_a_reason_for_operator_override(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
        ]);

        $this->expectException(ValidationException::class);

        app(SubscriptionLifecycleService::class)->resolveForClient(
            $client,
            (int) $platform->id,
            SubscriptionLifecycleService::LIFECYCLE_RENEWAL,
            null
        );
    }

    public function test_it_records_operator_override_when_reason_is_supplied(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
        ]);

        $resolved = app(SubscriptionLifecycleService::class)->resolveForClient(
            $client,
            (int) $platform->id,
            SubscriptionLifecycleService::LIFECYCLE_RENEWAL,
            'Client had a prior off-system package.'
        );

        $this->assertSame(SubscriptionLifecycleService::LIFECYCLE_RENEWAL, $resolved['subscription_lifecycle']);
        $this->assertSame(SubscriptionLifecycleService::SOURCE_OPERATOR_OVERRIDE, $resolved['subscription_lifecycle_source']);
        $this->assertSame('Client had a prior off-system package.', $resolved['subscription_lifecycle_reason']);
        $this->assertTrue($resolved['operator_overridden']);
    }
}
