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

    public function test_it_uses_kenyan_prefix_by_default(): void
    {
        $this->assertSame('254748612016', PhoneNormalizer::normalize('0748612016'));
    }

    public function test_it_normalizes_local_numbers_without_a_country_code(): void
    {
        $this->assertSame('254748612016', PhoneNormalizer::normalize('748612016', '254'));
    }

    public function test_it_prepends_prefix_to_short_numbers_without_country_code(): void
    {
        $this->assertSame('254712345678', PhoneNormalizer::normalize('712345678', '254'));
    }

    public function test_it_keeps_valid_international_numbers_in_digit_format(): void
    {
        $this->assertSame('254748612016', PhoneNormalizer::normalize('+254748612016', '254'));
    }

    public function test_it_keeps_plain_international_numbers_in_digit_format(): void
    {
        $this->assertSame('254748612016', PhoneNormalizer::normalize('254748612016', '254'));
    }

    public function test_it_strips_double_zero_international_prefix(): void
    {
        $this->assertSame('254748612016', PhoneNormalizer::normalize('00254748612016', '254'));
    }
}
