<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * subscriptions:check is bookkeeping only. It must never expire a profile again.
 * The behavioural guards live in SubscriptionExpiryOwnershipTest.
 */
class CheckExpiredSubscriptionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_returns_success_when_no_expired_subscriptions_are_found(): void
    {
        Http::fake();

        $this->artisan('subscriptions:check')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }
}
