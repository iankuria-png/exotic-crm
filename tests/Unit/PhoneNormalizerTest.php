<?php

namespace Tests\Unit;

use App\Support\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    public function test_it_normalizes_local_phone_using_custom_prefix(): void
    {
        $this->assertSame('255712345678', PhoneNormalizer::normalize('0712345678', '255'));
    }

    public function test_it_returns_null_for_empty_phone(): void
    {
        $this->assertNull(PhoneNormalizer::normalize(null, '255'));
        $this->assertNull(PhoneNormalizer::normalize('', '255'));
    }
}
