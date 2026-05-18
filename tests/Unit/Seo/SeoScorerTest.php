<?php

namespace Tests\Unit\Seo;

use App\Services\Seo\ProfileSnapshot;
use App\Services\Seo\SeoScorer;
use Tests\TestCase;

/**
 * SeoScorer reads config() so it needs the Laravel TestCase, not raw PHPUnit.
 */
class SeoScorerTest extends TestCase
{
    private SeoScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new SeoScorer();
    }

    public function test_word_count_band_full_credit_120_to_300(): void
    {
        $words = str_repeat('word ', 150);
        $result = $this->scorer->score("<p>{$words}</p>", $this->emptyProfile());
        $this->assertSame(25, $result['breakdown']['word_count']);
    }

    public function test_word_count_zero_below_50(): void
    {
        $words = str_repeat('word ', 20);
        $result = $this->scorer->score("<p>{$words}</p>", $this->emptyProfile());
        $this->assertSame(0, $result['breakdown']['word_count']);
    }

    public function test_word_count_zero_above_600(): void
    {
        $words = str_repeat('word ', 700);
        $result = $this->scorer->score("<p>{$words}</p>", $this->emptyProfile());
        $this->assertSame(0, $result['breakdown']['word_count']);
    }

    public function test_links_full_credit_3_to_6(): void
    {
        $bio = '<p>' . str_repeat('word ', 150) . '</p>'
            . '<a href="/a">a</a><a href="/b">b</a><a href="/c">c</a><a href="/d">d</a>';
        $result = $this->scorer->score($bio, $this->emptyProfile());
        $this->assertSame(25, $result['breakdown']['links']);
    }

    public function test_links_partial_credit_for_one_or_two(): void
    {
        $bio = '<p>' . str_repeat('word ', 150) . '</p><a href="/a">a</a><a href="/b">b</a>';
        $result = $this->scorer->score($bio, $this->emptyProfile());
        // 25 * 0.48 = 12
        $this->assertSame(12, $result['breakdown']['links']);
    }

    public function test_links_zero_when_none(): void
    {
        $result = $this->scorer->score('<p>some text</p>', $this->emptyProfile());
        $this->assertSame(0, $result['breakdown']['links']);
    }

    public function test_links_zero_when_too_many(): void
    {
        $bio = str_repeat('<a href="/x">x</a>', 11);
        $result = $this->scorer->score($bio, $this->emptyProfile());
        $this->assertSame(0, $result['breakdown']['links']);
    }

    public function test_completeness_scales_with_filled_fields(): void
    {
        // empty profile = all empty -> 0 completeness
        $this->assertSame(0, $this->scorer->score('', $this->emptyProfile())['breakdown']['completeness']);

        // full profile = max completeness
        $full = $this->makeSnapshot(
            name: 'Anna',
            age: 25,
            city: 'Nairobi',
            services: ['GFE'],
            height: '170',
            ethnicity: 'African',
            build: 'slim',
            hairColor: 'black',
            availability: 'incall',
            languages: ['English'],
            rates: ['1h' => 5000],
            neighborhood: 'Westlands',
        );
        $this->assertSame(25, $this->scorer->score('', $full)['breakdown']['completeness']);
    }

    public function test_media_score_includes_main_image_and_video(): void
    {
        $profile = $this->makeSnapshot(mediaSummary: [
            'image_count' => 3,
            'video_count' => 1,
            'has_main_image' => true,
        ]);
        $result = $this->scorer->score('', $profile);
        // raw 15 + 5 + 5 = 25 -> full 25 points
        $this->assertSame(25, $result['breakdown']['media']);
    }

    public function test_media_score_zero_with_no_media(): void
    {
        $result = $this->scorer->score('', $this->emptyProfile());
        $this->assertSame(0, $result['breakdown']['media']);
    }

    public function test_total_is_capped_at_100(): void
    {
        $words = str_repeat('word ', 150);
        $bio = '<p>' . $words . '</p>'
            . '<a href="/a">a</a><a href="/b">b</a><a href="/c">c</a><a href="/d">d</a>';
        $profile = $this->makeSnapshot(
            name: 'Anna',
            age: 25,
            city: 'Nairobi',
            services: ['GFE'],
            height: '170',
            ethnicity: 'African',
            build: 'slim',
            hairColor: 'black',
            availability: 'incall',
            languages: ['English'],
            rates: ['1h' => 5000],
            neighborhood: 'Westlands',
            mediaSummary: ['image_count' => 5, 'video_count' => 1, 'has_main_image' => true],
        );
        $result = $this->scorer->score($bio, $profile);
        $this->assertSame(100, $result['total']);
    }

    public function test_color_band_thresholds(): void
    {
        $this->assertSame('green', SeoScorer::colorBand(70));
        $this->assertSame('green', SeoScorer::colorBand(85));
        $this->assertSame('amber', SeoScorer::colorBand(69));
        $this->assertSame('amber', SeoScorer::colorBand(40));
        $this->assertSame('red', SeoScorer::colorBand(39));
        $this->assertSame('red', SeoScorer::colorBand(0));
    }

    // ------------------------------------------------------------

    private function emptyProfile(): ProfileSnapshot
    {
        return $this->makeSnapshot();
    }

    private function makeSnapshot(
        string $name = '',
        ?int $age = null,
        string $city = '',
        array $services = [],
        ?string $height = null,
        ?string $ethnicity = null,
        ?string $build = null,
        ?string $hairColor = null,
        ?string $availability = null,
        array $languages = [],
        array $rates = [],
        ?string $neighborhood = null,
        array $mediaSummary = [],
    ): ProfileSnapshot {
        return new ProfileSnapshot(
            clientId: null,
            wpPostId: null,
            platformId: 1,
            name: $name,
            age: $age,
            city: $city,
            neighborhood: $neighborhood,
            gender: 'female',
            ethnicity: $ethnicity,
            build: $build,
            height: $height,
            hairColor: $hairColor,
            services: $services,
            languages: $languages,
            rates: $rates,
            availability: $availability,
            existingBio: '',
            mediaSummary: $mediaSummary,
        );
    }
}
