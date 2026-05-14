<?php

namespace Tests\Unit;

use App\Services\CurrencyCanonicalizer;
use Tests\TestCase;

class CurrencyCanonicalizerTest extends TestCase
{
    private CurrencyCanonicalizer $canonicalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->canonicalizer = new CurrencyCanonicalizer();
    }

    // --- CFA zone resolution ---

    public function test_resolves_cfa_to_xof_for_cote_divoire_unicode_country(): void
    {
        $result = $this->canonicalizer->resolve('CFA', [
            'platform_country' => "Côte d'Ivoire",
            'platform_name'    => "Côte d'Ivoire",
        ]);

        $this->assertSame('XOF', $result['code']);
        $this->assertSame('canonicalized', $result['status']);
    }

    public function test_resolves_cfa_to_xof_for_senegal(): void
    {
        $result = $this->canonicalizer->resolve('CFA', [
            'platform_country' => 'Senegal',
        ]);

        $this->assertSame('XOF', $result['code']);
        $this->assertSame('canonicalized', $result['status']);
    }

    public function test_resolves_cfa_to_xaf_for_gabon(): void
    {
        $result = $this->canonicalizer->resolve('CFA', [
            'platform_country' => 'Gabon',
        ]);

        $this->assertSame('XAF', $result['code']);
        $this->assertSame('canonicalized', $result['status']);
    }

    public function test_cfa_is_ambiguous_without_context(): void
    {
        $result = $this->canonicalizer->resolve('CFA', []);

        $this->assertNull($result['code']);
        $this->assertSame('ambiguous', $result['status']);
    }

    // --- Simple aliases ---

    public function test_resolves_ksh_alias(): void
    {
        $result = $this->canonicalizer->resolve('KSH', []);

        $this->assertSame('KES', $result['code']);
        $this->assertSame('canonicalized', $result['status']);
    }

    // --- Standard ISO codes ---

    public function test_passes_through_iso_code_unchanged(): void
    {
        $result = $this->canonicalizer->resolve('USD', []);

        $this->assertSame('USD', $result['code']);
        $this->assertSame('canonical', $result['status']);
    }

    public function test_blank_currency_returns_missing(): void
    {
        $result = $this->canonicalizer->resolve('', []);

        $this->assertNull($result['code']);
        $this->assertSame('missing', $result['status']);
    }
}
