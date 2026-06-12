<?php

namespace Tests\Unit\Support;

use App\Support\CityNormalizer;
use PHPUnit\Framework\TestCase;

class CityNormalizerTest extends TestCase
{
    public function test_it_prefers_the_taxonomy_city_name_when_present(): void
    {
        $payload = [
            'city' => '84',
            'taxonomies' => [
                'city' => [
                    'name' => 'Lubumbashi',
                ],
            ],
            'meta' => [
                'city' => '105',
            ],
        ];

        $this->assertSame('Lubumbashi', CityNormalizer::fromWpPayload($payload));
    }

    public function test_it_rejects_numeric_city_codes_without_a_label(): void
    {
        $payload = [
            'city' => '21',
            'meta' => [
                'city' => '84',
            ],
        ];

        $this->assertNull(CityNormalizer::fromWpPayload($payload));
    }

    public function test_it_builds_a_canonical_key_for_grouping(): void
    {
        $this->assertSame('nairobi', CityNormalizer::canonicalKey('  Nairobi '));
        $this->assertSame('kisumu town', CityNormalizer::canonicalKey('Kisumu  Town'));
        $this->assertSame(
            CityNormalizer::canonicalKey('nairobi'),
            CityNormalizer::canonicalKey('Nairobi')
        );
        $this->assertNull(CityNormalizer::canonicalKey('12345'));
        $this->assertNull(CityNormalizer::canonicalKey('   '));
    }
}
