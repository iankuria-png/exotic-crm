<?php

namespace Tests\Unit;

use App\Support\BioSiteLinkStripper;
use PHPUnit\Framework\TestCase;

class BioSiteLinkStripperTest extends TestCase
{
    /** Root-relative SEO links are the ones that 404 on a PBN. */
    public function test_root_relative_links_are_unwrapped(): void
    {
        $html = '<p>She is into <a href="/bdsm-escorts/">BDSM</a> and <a href="/massage/">massage</a>.</p>';

        $this->assertSame(
            '<p>She is into BDSM and massage.</p>',
            BioSiteLinkStripper::strip($html)
        );
    }

    /** Contact links work from any host and are how the enquiry arrives. */
    public function test_contact_links_survive(): void
    {
        $html = '<p>Call <a href="tel:+256700000000">256700000000</a> or '
            . '<a href="https://wa.me/256700000000">WhatsApp</a> or '
            . '<a href="mailto:her@example.com">email</a>.</p>';

        $this->assertSame($html, BioSiteLinkStripper::strip($html));
    }

    /**
     * An absolute link home is worse than a 404 on a PBN: it hands a crawler an
     * explicit edge between the two sites.
     */
    public function test_links_back_to_the_source_market_are_unwrapped(): void
    {
        $html = '<p>See <a href="https://www.exoticuganda.com/bdsm-escorts/">BDSM escorts</a>.</p>';

        $this->assertSame(
            '<p>See BDSM escorts.</p>',
            BioSiteLinkStripper::strip($html, ['exoticuganda.com'])
        );
    }

    /** A genuine outbound link to an unrelated site is left alone. */
    public function test_unrelated_outbound_links_are_left_alone(): void
    {
        $html = '<p>As seen on <a href="https://example.com/feature">example</a>.</p>';

        $this->assertSame($html, BioSiteLinkStripper::strip($html, ['exoticuganda.com']));
    }

    public function test_empty_and_link_free_bios_are_returned_untouched(): void
    {
        $this->assertSame('', BioSiteLinkStripper::strip(''));
        $this->assertSame('<p>No links here.</p>', BioSiteLinkStripper::strip('<p>No links here.</p>'));
    }

    /** Nested markup inside the anchor is preserved when it is unwrapped. */
    public function test_inner_markup_survives_unwrapping(): void
    {
        $html = '<p><a href="/incall/"><strong>Incall</strong></a> available.</p>';

        $this->assertSame('<p><strong>Incall</strong> available.</p>', BioSiteLinkStripper::strip($html));
    }

    public function test_anchor_without_href_is_left_alone(): void
    {
        $html = '<p><a name="top">Top</a></p>';

        $this->assertSame($html, BioSiteLinkStripper::strip($html));
    }
}
