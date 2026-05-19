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

        $this->assertSame('/bdsm/', $bdsm['url'] ?? null);
        $this->assertSame('service:bdsm', $bdsm['category'] ?? null);
        $this->assertSame('/couples/', $couples['url'] ?? null);
        $this->assertSame('service:couples', $couples['category'] ?? null);
    }
}
