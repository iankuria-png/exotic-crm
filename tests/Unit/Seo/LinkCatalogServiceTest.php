<?php

namespace Tests\Unit\Seo;

use App\Services\Seo\LinkCatalogService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LinkCatalogServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_includes_market_relative_service_pages(): void
    {
        $catalog = app(LinkCatalogService::class)->forPlatform(999999);

        $bdsm = collect($catalog)->firstWhere('keyword', 'BDSM');
        $couples = collect($catalog)->firstWhere('keyword', 'Couples');

        $this->assertSame('/bdsm-escorts/', $bdsm['url'] ?? null);
        $this->assertSame('service:bdsm-escorts', $bdsm['category'] ?? null);
        $this->assertSame('/couples-escorts/', $couples['url'] ?? null);
        $this->assertSame('service:couples-escorts', $couples['category'] ?? null);
    }

    public function test_includes_conservative_market_relative_attribute_pages(): void
    {
        $catalog = app(LinkCatalogService::class)->forPlatform(999998);

        $black = collect($catalog)->firstWhere('keyword', 'Black');
        $curvy = collect($catalog)->firstWhere('keyword', 'Curvy');

        $this->assertSame('/black-escorts/', $black['url'] ?? null);
        $this->assertSame('attribute:black-escorts', $black['category'] ?? null);
        $this->assertSame('/curvy-escorts/', $curvy['url'] ?? null);
        $this->assertSame('attribute:curvy-escorts', $curvy['category'] ?? null);
    }
}
