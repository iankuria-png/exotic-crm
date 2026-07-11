<?php

namespace Tests\Unit\Console;

use App\Console\Commands\RepairBioLinksCommand;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class RepairBioLinksRewriteTest extends TestCase
{
    private function rewrite(string $html): array
    {
        $method = new ReflectionMethod(RepairBioLinksCommand::class, 'rewrite');
        $method->setAccessible(true);

        return $method->invoke(new RepairBioLinksCommand(), $html);
    }

    public function test_renames_bare_service_slug_to_escorts_suffix(): void
    {
        [$out, $renamed, $unwrapped] = $this->rewrite('<p>I enjoy <a href="/bdsm/">BDSM</a> sessions.</p>');

        $this->assertSame('<p>I enjoy <a href="/bdsm-escorts/">BDSM</a> sessions.</p>', $out);
        $this->assertSame(1, $renamed);
        $this->assertSame(0, $unwrapped);
    }

    public function test_unwraps_mature_link_keeping_the_word(): void
    {
        [$out, $renamed, $unwrapped] = $this->rewrite('<p>Great <a href="/mature/">mature</a> company.</p>');

        $this->assertSame('<p>Great mature company.</p>', $out);
        $this->assertSame(0, $renamed);
        $this->assertSame(1, $unwrapped);
    }

    public function test_leaves_valid_links_untouched(): void
    {
        $html = '<p>Based in <a href="/escorts-from/nairobi-escorts/">Nairobi</a>, offering '
            . '<a href="/escort/">escort</a> time and <a href="/massage-escorts/">massage</a>. '
            . 'Reach me on <a href="https://wa.me/254759076723">WhatsApp</a>.</p>';

        [$out, $renamed, $unwrapped] = $this->rewrite($html);

        $this->assertSame($html, $out);
        $this->assertSame(0, $renamed);
        $this->assertSame(0, $unwrapped);
    }

    public function test_does_not_touch_profile_paths_that_share_a_prefix(): void
    {
        // /escort/shanel/ is an individual profile, not the broken /escort/ archive link.
        $html = '<p><a href="/escort/shanel/">Shanel</a></p>';

        [$out, $renamed, $unwrapped] = $this->rewrite($html);

        $this->assertSame($html, $out);
        $this->assertSame(0, $renamed);
        $this->assertSame(0, $unwrapped);
    }

    public function test_handles_multiple_broken_links_in_one_bio(): void
    {
        $html = '<p><a href="/bdsm/">BDSM</a>, <a href="/couples/">couples</a>, '
            . '<a href="/black/">black</a> and <a href="/mature/">mature</a>.</p>';

        [$out, $renamed, $unwrapped] = $this->rewrite($html);

        $this->assertStringContainsString('<a href="/bdsm-escorts/">BDSM</a>', $out);
        $this->assertStringContainsString('<a href="/couples-escorts/">couples</a>', $out);
        $this->assertStringContainsString('<a href="/black-escorts/">black</a>', $out);
        $this->assertStringContainsString('and mature.', $out);
        $this->assertSame(3, $renamed);
        $this->assertSame(1, $unwrapped);
    }

    public function test_is_idempotent(): void
    {
        $html = '<p><a href="/bdsm/">BDSM</a> and <a href="/mature/">mature</a>.</p>';

        [$once] = $this->rewrite($html);
        [$twice, $renamed, $unwrapped] = $this->rewrite($once);

        $this->assertSame($once, $twice);
        $this->assertSame(0, $renamed);
        $this->assertSame(0, $unwrapped);
    }

    public function test_handles_single_quoted_hrefs(): void
    {
        [$out, $renamed] = $this->rewrite("<p><a href='/massage/'>massage</a></p>");

        $this->assertSame("<p><a href='/massage-escorts/'>massage</a></p>", $out);
        $this->assertSame(1, $renamed);
    }
}
