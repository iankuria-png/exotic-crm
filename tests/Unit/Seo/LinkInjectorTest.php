<?php

namespace Tests\Unit\Seo;

use App\Services\Seo\LinkInjector;
use PHPUnit\Framework\TestCase;

class LinkInjectorTest extends TestCase
{
    private LinkInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->injector = new LinkInjector();
    }

    public function test_injects_single_link_for_keyword(): void
    {
        $html = '<p>Escorts in Nairobi are amazing.</p>';
        $catalog = [
            ['keyword' => 'Nairobi', 'url' => '/escorts/nairobi', 'category' => 'location', 'priority' => 10],
        ];
        $result = $this->injector->inject($html, $catalog);
        $this->assertStringContainsString('<a href="/escorts/nairobi">Nairobi</a>', $result);
    }

    public function test_returns_original_html_when_catalog_empty(): void
    {
        $html = '<p>Original text.</p>';
        $this->assertSame($html, $this->injector->inject($html, []));
    }

    public function test_returns_empty_when_html_empty(): void
    {
        $catalog = [['keyword' => 'X', 'url' => '/x', 'category' => 'c', 'priority' => 1]];
        $this->assertSame('', $this->injector->inject('', $catalog));
    }

    public function test_does_not_wrap_inside_existing_anchor(): void
    {
        $html = '<p>Visit <a href="/elsewhere">Nairobi today</a> for fun.</p>';
        $catalog = [
            ['keyword' => 'Nairobi', 'url' => '/escorts/nairobi', 'category' => 'location', 'priority' => 10],
        ];
        $result = $this->injector->inject($html, $catalog);
        // Only the original anchor should remain — no new one inside it.
        $this->assertSame(1, substr_count($result, '<a '));
    }

    public function test_whole_word_match_only(): void
    {
        // 'cat' should NOT match 'category' because it's not a whole word
        $html = '<p>This is a category here.</p>';
        $catalog = [
            ['keyword' => 'cat', 'url' => '/cat', 'category' => 'animal', 'priority' => 10],
        ];
        $result = $this->injector->inject($html, $catalog);
        $this->assertStringNotContainsString('<a', $result);
    }

    public function test_case_insensitive_match(): void
    {
        $html = '<p>Welcome to nairobi.</p>';
        $catalog = [
            ['keyword' => 'Nairobi', 'url' => '/n', 'category' => 'loc', 'priority' => 5],
        ];
        $result = $this->injector->inject($html, $catalog);
        $this->assertStringContainsString('<a href="/n">nairobi</a>', $result);
    }

    public function test_caps_total_links_at_six(): void
    {
        $html = '<p>one two three four five six seven eight</p>';
        $catalog = [];
        foreach (['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight'] as $i => $kw) {
            // each in its own category so per-category cap doesn't kick in
            $catalog[] = ['keyword' => $kw, 'url' => "/{$kw}", 'category' => "cat-{$i}", 'priority' => 10];
        }
        $result = $this->injector->inject($html, $catalog);
        $this->assertSame(6, substr_count($result, '<a '));
    }

    public function test_caps_per_category_at_two(): void
    {
        $html = '<p>alpha beta gamma delta epsilon</p>';
        $catalog = [
            ['keyword' => 'alpha',   'url' => '/a', 'category' => 'loc', 'priority' => 10],
            ['keyword' => 'beta',    'url' => '/b', 'category' => 'loc', 'priority' => 10],
            ['keyword' => 'gamma',   'url' => '/g', 'category' => 'loc', 'priority' => 10],
            ['keyword' => 'delta',   'url' => '/d', 'category' => 'loc', 'priority' => 10],
            ['keyword' => 'epsilon', 'url' => '/e', 'category' => 'loc', 'priority' => 10],
        ];
        $result = $this->injector->inject($html, $catalog);
        $this->assertSame(2, substr_count($result, '<a '));
    }

    public function test_skips_duplicate_url(): void
    {
        $html = '<p>alpha beta gamma</p>';
        $catalog = [
            ['keyword' => 'alpha', 'url' => '/same', 'category' => 'c1', 'priority' => 10],
            ['keyword' => 'beta',  'url' => '/same', 'category' => 'c2', 'priority' => 10],
        ];
        $result = $this->injector->inject($html, $catalog);
        $this->assertSame(1, substr_count($result, '<a '));
    }

    public function test_skips_empty_keyword_or_url(): void
    {
        $html = '<p>alpha beta</p>';
        $catalog = [
            ['keyword' => '',      'url' => '/x', 'category' => 'c', 'priority' => 1],
            ['keyword' => 'alpha', 'url' => '',   'category' => 'c', 'priority' => 1],
        ];
        $result = $this->injector->inject($html, $catalog);
        $this->assertStringNotContainsString('<a', $result);
    }

    public function test_priority_ordering_picks_higher_first(): void
    {
        $html = '<p>alpha</p>';
        $catalog = [
            // both target same word — higher priority should win
            ['keyword' => 'alpha', 'url' => '/low',  'category' => 'a', 'priority' => 1],
            ['keyword' => 'alpha', 'url' => '/high', 'category' => 'b', 'priority' => 10],
        ];
        $result = $this->injector->inject($html, $catalog);
        $this->assertStringContainsString('href="/high"', $result);
        $this->assertStringNotContainsString('href="/low"', $result);
    }

    public function test_longer_keyword_preferred_at_same_priority(): void
    {
        // 'Nairobi CBD' should be tried before 'Nairobi' so we don't partial-wrap
        $html = '<p>Welcome to Nairobi CBD today.</p>';
        $catalog = [
            ['keyword' => 'Nairobi',     'url' => '/nairobi',     'category' => 'a', 'priority' => 5],
            ['keyword' => 'Nairobi CBD', 'url' => '/nairobi-cbd', 'category' => 'b', 'priority' => 5],
        ];
        $result = $this->injector->inject($html, $catalog);
        $this->assertStringContainsString('<a href="/nairobi-cbd">Nairobi CBD</a>', $result);
    }
}
