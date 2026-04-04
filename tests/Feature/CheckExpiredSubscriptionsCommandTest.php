<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckExpiredSubscriptionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_returns_success_when_no_expired_subscriptions_are_found(): void
    {
        $this->artisan('subscriptions:check')
            ->assertExitCode(0);
    }
}
