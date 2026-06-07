<?php

namespace Tests\Unit\Seo;

use App\Services\Seo\ProfileSnapshot;
use App\Services\Seo\SeoScorer;
use Tests\TestCase;

class SeoScorerWeightsTest extends TestCase
{
    public function test_custom_weights_change_total_score(): void
    {
        $scorer = new SeoScorer();
        $profile = $this->profile();
        $bio = '<p>' . str_repeat('word ', 60) . '</p><a href="/one">one</a><a href="/two">two</a>';

        $default = $scorer->score($bio, $profile);
        $weighted = $scorer->score($bio, $profile, [
            'word_count' => 10,
            'links' => 10,
            'completeness' => 60,
            'media' => 20,
        ]);

        $this->assertNotSame($default['total'], $weighted['total']);
        $this->assertSame(60, $weighted['breakdown']['completeness']);
    }

    public function test_null_weights_preserve_config_backed_scores(): void
    {
        config([
            'services.seo_engine.scorer_weights' => [
                'word_count' => 20,
                'links' => 30,
                'completeness' => 40,
                'media' => 10,
            ],
        ]);

        $scorer = new SeoScorer();
        $profile = $this->profile();
        $bio = '<p>' . str_repeat('word ', 60) . '</p><a href="/one">one</a><a href="/two">two</a><a href="/three">three</a>';

        $expected = $scorer->score($bio, $profile);
        $actual = $scorer->score($bio, $profile, null);

        $this->assertSame($expected, $actual);
    }

    private function profile(): ProfileSnapshot
    {
        return new ProfileSnapshot(
            clientId: null,
            wpPostId: null,
            platformId: 1,
            name: 'Amina',
            age: 24,
            city: 'Nairobi',
            neighborhood: 'Westlands',
            gender: 'Female',
            ethnicity: 'African',
            build: 'slim',
            height: '170',
            hairColor: 'black',
            services: ['GFE'],
            languages: ['English'],
            rates: ['1h' => 100],
            availability: 'Incall',
            existingBio: '',
            mediaSummary: ['image_count' => 3, 'video_count' => 0, 'has_main_image' => true],
        );
    }
}
