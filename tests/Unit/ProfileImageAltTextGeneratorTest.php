<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Platform;
use App\Services\Seo\ProfileImageAltTextGenerator;
use PHPUnit\Framework\TestCase;

class ProfileImageAltTextGeneratorTest extends TestCase
{
    private function client(string $name, string $city, string $country, string $type = 'escort'): Client
    {
        $client = new Client(['name' => $name, 'city' => $city, 'client_type' => $type]);
        $client->setRelation('platform', new Platform(['country' => $country]));

        return $client;
    }

    private function generate(Client $client, int $position = 1): string
    {
        return (new ProfileImageAltTextGenerator())->generate($client, $position);
    }

    public function test_it_builds_name_role_and_location(): void
    {
        $this->assertSame(
            'Naima Yemeni, escort in Westlands, Kenya',
            $this->generate($this->client('Naima Yemeni', 'Westlands', 'Kenya'))
        );
    }

    public function test_it_numbers_every_image_after_the_first(): void
    {
        $client = $this->client('Naima Yemeni', 'Westlands', 'Kenya');

        $this->assertSame('Naima Yemeni, escort in Westlands, Kenya', $this->generate($client, 1));
        $this->assertSame('Naima Yemeni, escort in Westlands, Kenya (photo 3)', $this->generate($client, 3));
    }

    public function test_it_never_repeats_alt_text_across_a_gallery(): void
    {
        $client = $this->client('Naima Yemeni', 'Westlands', 'Kenya');

        $alts = [];
        for ($position = 1; $position <= 20; $position++) {
            $alts[] = $this->generate($client, $position);
        }

        $this->assertCount(20, array_unique($alts));
    }

    public function test_it_omits_no_prefix_like_photo_of(): void
    {
        $alt = $this->generate($this->client('Naima', 'Westlands', 'Kenya'));

        $this->assertStringNotContainsStringIgnoringCase('photo of', $alt);
        $this->assertStringNotContainsStringIgnoringCase('image of', $alt);
        $this->assertStringNotContainsStringIgnoringCase('picture of', $alt);
    }

    public function test_it_cleans_display_junk_out_of_names(): void
    {
        // Underscore becomes a space; the resulting mixed case is left alone.
        $this->assertSame(
            'Baby love, escort in Kilimani, Kenya',
            $this->generate($this->client('Baby_love', 'Kilimani', 'Kenya'))
        );

        $this->assertSame(
            'Sexy Diva, escort in Kilimani, Kenya',
            $this->generate($this->client('😍💋 Sexy Diva 💋', 'Kilimani', 'Kenya'))
        );
    }

    public function test_it_title_cases_only_when_the_source_has_no_casing_signal(): void
    {
        $this->assertStringStartsWith('Vivianna,', $this->generate($this->client('vivianna', 'Karen', 'Kenya')));
        $this->assertStringStartsWith('Goodsex7,', $this->generate($this->client('GOODSEX7', 'Karen', 'Kenya')));
        // Mixed case carries intent, so it is left alone.
        $this->assertStringStartsWith("McKenzie O'Brien,", $this->generate($this->client("McKenzie O'Brien", 'Karen', 'Kenya')));
    }

    public function test_it_does_not_repeat_a_city_that_equals_the_country(): void
    {
        $this->assertSame(
            'Naima, escort in Kenya',
            $this->generate($this->client('Naima', 'Kenya', 'Kenya'))
        );
    }

    public function test_it_degrades_when_location_or_name_is_missing(): void
    {
        $this->assertSame('Naima, escort in Kenya', $this->generate($this->client('Naima', '', 'Kenya')));
        $this->assertSame('Escort in Kilimani, Kenya', $this->generate($this->client('', 'Kilimani', 'Kenya')));
        $this->assertSame('Profile photo', $this->generate($this->client('', '', '')));
        $this->assertSame('Profile photo 4', $this->generate($this->client('', '', ''), 4));
    }

    public function test_it_drops_an_unknown_client_type_rather_than_printing_it(): void
    {
        $alt = $this->generate($this->client('Naima', 'Kilimani', 'Kenya', 'mystery_type'));

        $this->assertSame('Naima in Kilimani, Kenya', $alt);
        $this->assertStringNotContainsString('mystery_type', $alt);
    }

    public function test_it_stays_within_the_screen_reader_limit(): void
    {
        $client = $this->client(str_repeat('Averyverylongdisplayname ', 8), str_repeat('Longplacename ', 5), 'Kenya');

        foreach ([1, 7, 20] as $position) {
            $this->assertLessThanOrEqual(
                ProfileImageAltTextGenerator::MAX_LENGTH,
                mb_strlen($this->generate($client, $position))
            );
        }
    }

    public function test_it_is_deterministic(): void
    {
        $client = $this->client('Naima Yemeni', 'Westlands', 'Kenya');

        $this->assertSame($this->generate($client, 2), $this->generate($client, 2));
    }
}
