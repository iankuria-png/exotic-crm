<?php

namespace Tests\Unit;

use App\Support\BioContactScrubber;
use PHPUnit\Framework\TestCase;

class BioContactScrubberTest extends TestCase
{
    private const REDACT = BioContactScrubber::PLACEHOLDER;

    /**
     * Real bio from a South Sudan profile: a tel: anchor. The href must go too —
     * redacting only the visible text leaves a clickable number behind.
     */
    public function test_redacts_tel_anchor_including_the_href(): void
    {
        $bio = '<p>I keep things simple and real.</p><p>Reach me: <a href="tel:+221925350648">+221925350648</a>.</p>';

        $result = BioContactScrubber::scrub($bio);

        $this->assertStringNotContainsString('221925350648', $result['clean']);
        $this->assertStringContainsString(self::REDACT, $result['clean']);
        $this->assertStringContainsString('I keep things simple and real', $result['clean']);
    }

    /**
     * Real bio from a Kenya profile: bare number in prose, alongside SEO bio
     * links that must survive untouched.
     */
    public function test_redacts_bare_number_while_preserving_seo_links(): void
    {
        $bio = '<p>I am a <a href="https://exotickenya.com/curvy/">curvy</a> girl in '
            . '<a href="https://exotickenya.com/escorts-from/kilimani/">Kilimani</a>.</p>'
            . '<p>hit me up on WhatsApp at +254143324253.</p>';

        $result = BioContactScrubber::scrub($bio);

        $this->assertStringNotContainsString('254143324253', $result['clean']);
        $this->assertStringContainsString('escorts-from/kilimani/', $result['clean']);
        $this->assertStringContainsString('>Kilimani</a>', $result['clean']);
        $this->assertStringContainsString('https://exotickenya.com/curvy/', $result['clean']);
    }

    /**
     * @dataProvider contactBios
     */
    public function test_redacts_contact_details(string $label, string $bio): void
    {
        $result = BioContactScrubber::scrub('<p>' . $bio . '</p>');

        $this->assertStringContainsString(self::REDACT, $result['clean'], $label);
        $this->assertGreaterThan(0, $result['redactions'], $label);
    }

    public static function contactBios(): array
    {
        return [
            ['spaced local format', 'Call me on 0712 345 678 anytime'],
            ['dotted', 'reach me 0712.345.678'],
            ['dashed', 'text 0712-345-678'],
            ['digit-by-digit obfuscation', 'my digits 0 7 1 2 3 4 5 6 7 8 ok'],
            ['bracketed international', 'ring +(254) 712 345 678'],
            ['bare wa.me link', 'find me https://wa.me/254712345678 now'],
            ['telegram link', 'telegram t.me/sashaxo'],
            ['email address', 'mail me sasha.love@gmail.com please'],
            ['social handle', 'snap me @sashaxoxo today'],
            ['mailto anchor', 'write <a href="mailto:x@y.com">here</a>'],
            ['wa.me anchor', '<a href="https://wa.me/254712345678">WhatsApp me</a>'],
        ];
    }

    /**
     * False-positive guards: a bio is full of legitimate numbers.
     *
     * @dataProvider safeBios
     */
    public function test_leaves_legitimate_numbers_alone(string $label, string $bio): void
    {
        $result = BioContactScrubber::scrub($bio);

        $this->assertStringNotContainsString(self::REDACT, $result['clean'], $label);
        $this->assertSame(0, $result['redactions'], $label);
        $this->assertSame($bio, $result['clean'], $label);
    }

    public static function safeBios(): array
    {
        return [
            ['height and weight', '<p>I am 160cm and 60kg, size 8.</p>'],
            ['age', '<p>I am 22 years old and love life.</p>'],
            ['availability', '<p>Available 24/7 for bookings.</p>'],
            ['rates', '<p>Rates from 5000 for one hour.</p>'],
            ['plain prose', '<p>Good conversation, genuine chemistry, no pressure.</p>'],
        ];
    }

    public function test_never_corrupts_tag_attributes(): void
    {
        $bio = '<p>See <img src="https://cdn.example.com/2026/07/photo-1843-1024x768.jpg" width="1024"> here</p>';

        $result = BioContactScrubber::scrub($bio);

        $this->assertStringContainsString('photo-1843-1024x768.jpg', $result['clean']);
        $this->assertStringContainsString('width="1024"', $result['clean']);
        $this->assertSame(0, $result['redactions']);
    }

    public function test_is_idempotent(): void
    {
        $bio = '<p>Reach me: <a href="tel:+221925350648">+221925350648</a> or me@example.com</p>';

        $once = BioContactScrubber::scrub($bio);
        $twice = BioContactScrubber::scrub($once['clean']);

        $this->assertSame($once['clean'], $twice['clean']);
        $this->assertSame(0, $twice['redactions'], 'a scrubbed bio has nothing left to redact');
    }

    public function test_reports_what_it_found_by_kind(): void
    {
        $bio = '<p>Call 0712 345 678, mail me@example.com, or https://wa.me/254712345678</p>';

        $result = BioContactScrubber::detect($bio);

        $this->assertSame(3, $result['redactions']);
        $this->assertArrayHasKey('phone', $result['kinds']);
        $this->assertArrayHasKey('email', $result['kinds']);
        $this->assertArrayHasKey('messenger', $result['kinds']);
        $this->assertArrayNotHasKey('clean', $result, 'detect() must not expose mutated text');
    }

    public function test_detect_does_not_mutate_and_flags_correctly(): void
    {
        $this->assertTrue(BioContactScrubber::hasContactDetails('<p>ring 0712 345 678</p>'));
        $this->assertFalse(BioContactScrubber::hasContactDetails('<p>I am 22 and 160cm.</p>'));
    }

    public function test_handles_empty_and_null_input(): void
    {
        $this->assertSame('', BioContactScrubber::scrub('')['clean']);
        $this->assertSame('', BioContactScrubber::scrub(null)['clean']);
        $this->assertSame(0, BioContactScrubber::scrub(null)['redactions']);
    }
}
