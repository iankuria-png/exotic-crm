<?php

namespace Tests\Unit;

use App\Support\ClientLifecycleState;
use PHPUnit\Framework\TestCase;

class ClientLifecycleStateTest extends TestCase
{
    public function test_all_states_are_declared_and_labelled(): void
    {
        $this->assertSame(['active', 'expired', 'archived', 'removed'], ClientLifecycleState::ALL);

        foreach (ClientLifecycleState::ALL as $state) {
            $this->assertArrayHasKey($state, ClientLifecycleState::LABELS);
            $this->assertNotSame('', ClientLifecycleState::label($state));
        }
    }

    public function test_publicly_restricted_covers_expired_and_archived_only(): void
    {
        $this->assertTrue(ClientLifecycleState::isPubliclyRestricted('expired'));
        $this->assertTrue(ClientLifecycleState::isPubliclyRestricted('archived'));
        $this->assertFalse(ClientLifecycleState::isPubliclyRestricted('active'));
        $this->assertFalse(ClientLifecycleState::isPubliclyRestricted('removed'));
        $this->assertFalse(ClientLifecycleState::isPubliclyRestricted(null));
    }

    public function test_normalize_defaults_unknown_values_to_active(): void
    {
        $this->assertSame('expired', ClientLifecycleState::normalize('EXPIRED'));
        $this->assertSame('archived', ClientLifecycleState::normalize(' archived '));
        $this->assertSame('active', ClientLifecycleState::normalize(''));
        $this->assertSame('active', ClientLifecycleState::normalize(null));
        $this->assertSame('active', ClientLifecycleState::normalize('nonsense'));
    }

    public function test_validity_check(): void
    {
        $this->assertTrue(ClientLifecycleState::isValid('archived'));
        $this->assertFalse(ClientLifecycleState::isValid('paused'));
    }
}
