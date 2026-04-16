<?php

namespace Tests\Unit\Support;

use App\Support\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    public function test_it_normalizes_local_numbers_with_a_leading_zero(): void
    {
        $this->assertSame('254748612016', PhoneNormalizer::normalize('0748612016', '254'));
    }

    public function test_it_normalizes_local_numbers_without_a_country_code(): void
    {
        $this->assertSame('254748612016', PhoneNormalizer::normalize('748612016', '254'));
    }

    public function test_it_keeps_valid_international_numbers_in_digit_format(): void
    {
        $this->assertSame('254748612016', PhoneNormalizer::normalize('+254748612016', '254'));
    }
}
