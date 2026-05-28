<?php

namespace Tests\Unit\Seo;

use App\Models\Client;
use App\Models\Platform;
use App\Services\Seo\BulkBioRowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BulkBioRowResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_parses_one_url_per_line(): void
    {
        $platform = Platform::factory()->create();
        Client::factory()->create([
            'platform_id'           => $platform->id,
            'wp_post_id'            => 101,
            'wp_profile_permalink'  => 'https://example.com/escort/jane/',
            'wp_profile_slug'       => 'jane',
            'name'                  => 'Jane',
        ]);

        $resolver = new BulkBioRowResolver();
        $rows = $resolver->parse(
            "https://example.com/escort/jane/\nhttps://example.com/escort/missing/",
            $platform->id,
        );

        $this->assertCount(2, $rows);
        $this->assertSame(101, $rows[0]['wp_post_id']);
        $this->assertSame('queued', $rows[0]['status']);
        $this->assertNull($rows[1]['wp_post_id']);
        $this->assertSame('unresolved', $rows[1]['status']);
    }

    public function test_parses_tab_separated_excel_rows(): void
    {
        $platform = Platform::factory()->create();
        Client::factory()->create([
            'platform_id'      => $platform->id,
            'wp_post_id'       => 202,
            'wp_profile_slug'  => 'amina-k',
            'name'             => 'Amina',
        ]);

        $paste = "Jane Doe\thttps://example.com/escort/amina-k/\tNairobi\nMore\tnotes\there";
        $rows = (new BulkBioRowResolver())->parse($paste, $platform->id);

        $this->assertCount(2, $rows);
        $this->assertSame(202, $rows[0]['wp_post_id']);
        $this->assertSame('queued', $rows[0]['status']);
        // Second row has no URL-shaped cell → falls back to first non-empty
        $this->assertSame('unresolved', $rows[1]['status']);
    }

    public function test_resolves_bare_post_id(): void
    {
        $platform = Platform::factory()->create();
        Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id'  => 555,
            'name'        => 'Bea',
        ]);

        $rows = (new BulkBioRowResolver())->parse("555", $platform->id);
        $this->assertSame(555, $rows[0]['wp_post_id']);
        $this->assertSame('queued', $rows[0]['status']);
    }

    public function test_resolves_slug(): void
    {
        $platform = Platform::factory()->create();
        Client::factory()->create([
            'platform_id'     => $platform->id,
            'wp_post_id'      => 777,
            'wp_profile_slug' => 'queen-bee',
            'name'            => 'Queen',
        ]);

        $rows = (new BulkBioRowResolver())->parse("queen-bee", $platform->id);
        $this->assertSame(777, $rows[0]['wp_post_id']);
    }

    public function test_dedupes_repeated_rows(): void
    {
        $platform = Platform::factory()->create();
        Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 1, 'wp_profile_slug' => 'a', 'name' => 'A']);

        $rows = (new BulkBioRowResolver())->parse("a\na\na", $platform->id);
        $this->assertCount(1, $rows);
    }

    public function test_caps_at_max_rows(): void
    {
        $platform = Platform::factory()->create();
        $lines = [];
        for ($i = 1; $i <= BulkBioRowResolver::MAX_ROWS + 50; $i++) {
            $lines[] = 'slug-' . $i;
        }
        $rows = (new BulkBioRowResolver())->parse(implode("\n", $lines), $platform->id);
        $this->assertCount(BulkBioRowResolver::MAX_ROWS, $rows);
    }

    public function test_summarize_counts_resolved_and_unresolved(): void
    {
        $resolver = new BulkBioRowResolver();
        $summary = $resolver->summarize([
            ['status' => 'queued'],
            ['status' => 'queued'],
            ['status' => 'unresolved'],
        ]);
        $this->assertSame(3, $summary['total']);
        $this->assertSame(2, $summary['resolved']);
        $this->assertSame(1, $summary['unresolved']);
    }

    public function test_cross_market_url_does_not_resolve(): void
    {
        $marketA = Platform::factory()->create();
        $marketB = Platform::factory()->create();
        Client::factory()->create([
            'platform_id'     => $marketA->id,
            'wp_post_id'      => 99,
            'wp_profile_slug' => 'shared-slug',
            'name'            => 'X',
        ]);

        $rows = (new BulkBioRowResolver())->parse("shared-slug", $marketB->id);
        $this->assertSame('unresolved', $rows[0]['status']);
    }
}
