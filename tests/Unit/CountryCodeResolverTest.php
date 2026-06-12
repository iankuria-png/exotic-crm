<?php

namespace Tests\Unit;

use App\Support\CountryCodeResolver;
use PHPUnit\Framework\TestCase;

class CountryCodeResolverTest extends TestCase
{
    public function test_it_maps_known_country_names_to_alpha2_codes(): void
    {
        $this->assertSame('KE', CountryCodeResolver::alpha2('Kenya'));
        $this->assertSame('CI', CountryCodeResolver::alpha2("Côte d'Ivoire"));
        $this->assertSame('CI', CountryCodeResolver::alpha2('Ivory Coast'));
    }

    public function test_it_returns_null_for_unknown_countries(): void
    {
        $this->assertNull(CountryCodeResolver::alpha2('Atlantis'));
        $this->assertNull(CountryCodeResolver::alpha2(null));
    }
}
