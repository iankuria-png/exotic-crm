<?php

namespace Tests\Unit;

use App\Support\CrossPlatformPhoneResolver;
use PHPUnit\Framework\TestCase;

class CrossPlatformPhoneResolverTest extends TestCase
{
    public function test_same_prefix_passthrough(): void
    {
        $resolver = new CrossPlatformPhoneResolver();

        $this->assertSame('255712345678', $resolver->resolve('255712345678', '255', '255'));
    }

    public function test_different_prefix_swap(): void
    {
        $resolver = new CrossPlatformPhoneResolver();

        $this->assertSame('250712345678', $resolver->resolve('255712345678', '255', '250'));
    }

    public function test_invalid_input_returns_null(): void
    {
        $resolver = new CrossPlatformPhoneResolver();

        $this->assertNull($resolver->resolve('', '255', '250'));
    }
}
