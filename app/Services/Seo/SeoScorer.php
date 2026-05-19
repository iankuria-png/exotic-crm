<?php

namespace App\Services\Seo;

/**
 * Pure scoring function. No DB or HTTP calls.
 * All data flows in via ProfileSnapshot.
 */
class SeoScorer
{
    public function score(string $bioHtml, ProfileSnapshot $profile): array
    {
        $weights = config('services.seo_engine.scorer_weights', [
            'word_count'   => 25,
            'links'        => 25,
            'completeness' => 25,
            'media'        => 25,
        ]);

        $wordCountScore   = $this->scoreWordCount($bioHtml, (int) $weights['word_count']);
        $linksScore       = $this->scoreLinks($bioHtml, (int) $weights['links']);
        $completenessScore = $this->scoreCompleteness($profile, (int) $weights['completeness']);
        $mediaScore       = $this->scoreMedia($profile, (int) $weights['media']);

        $total = $wordCountScore + $linksScore + $completenessScore + $mediaScore;

        return [
            'total' => min(100, max(0, (int) round($total))),
            'breakdown' => [
                'word_count'   => $wordCountScore,
                'links'        => $linksScore,
                'completeness' => $completenessScore,
                'media'        => $mediaScore,
            ],
        ];
    }

    public static function colorBand(int $score): string
    {
        if ($score >= 70) {
            return 'green';
        }

        if ($score >= 40) {
            return 'amber';
        }

        return 'red';
    }

    // -------------------------------------------------------------------------

    private function scoreWordCount(string $html, int $maxPoints): int
    {
        $text  = strip_tags($html);
        $words = str_word_count($text);

        if ($words < 20 || $words > 600) {
            return 0;
        }

        // The generator is now intentionally concise. Reward useful classified-style
        // bios instead of forcing long, repetitive copy.
        if ($words >= 45 && $words <= 180) {
            return $maxPoints;
        }

        if ($words < 45) {
            return (int) round($maxPoints * (($words - 20) / 25));
        }

        // words 181–600: linear penalty
        return (int) round($maxPoints * ((600 - $words) / 420));
    }

    private function scoreLinks(string $html, int $maxPoints): int
    {
        preg_match_all('/<a\s[^>]*href=["\'][^"\']*["\'][^>]*>/i', $html, $matches);
        $linkCount = count($matches[0]);

        return match (true) {
            $linkCount === 0          => 0,
            $linkCount <= 2           => (int) round($maxPoints * 0.48),
            $linkCount >= 3 && $linkCount <= 6 => $maxPoints,
            $linkCount >= 7 && $linkCount <= 10 => (int) round($maxPoints * 0.48),
            default                   => 0, // > 10
        };
    }

    private function scoreCompleteness(ProfileSnapshot $profile, int $maxPoints): int
    {
        $fields = [
            fn() => $profile->name !== '',
            fn() => $profile->age !== null,
            fn() => $profile->city !== '',
            fn() => !empty($profile->services),
            fn() => $profile->height !== null && $profile->height !== '',
            fn() => $profile->ethnicity !== null && $profile->ethnicity !== '',
            fn() => $profile->build !== null && $profile->build !== '',
            fn() => $profile->hairColor !== null && $profile->hairColor !== '',
            fn() => $profile->availability !== null,
            fn() => !empty($profile->languages),
            fn() => !empty($profile->rates),
            fn() => $profile->neighborhood !== null && $profile->neighborhood !== '',
        ];

        $filled = 0;
        foreach ($fields as $check) {
            if ($check()) {
                $filled++;
            }
        }

        return (int) round($maxPoints * ($filled / count($fields)));
    }

    private function scoreMedia(ProfileSnapshot $profile, int $maxPoints): int
    {
        $imageCount   = $profile->imageCount();
        $videoCount   = $profile->videoCount();
        $hasMainImage = $profile->hasMainImage();

        $imageScore = match (true) {
            $imageCount >= 3 => 15,
            $imageCount === 2 => 10,
            $imageCount === 1 => 5,
            default           => 0,
        };

        $mainImageScore = $hasMainImage ? 5 : 0;
        $videoScore     = $videoCount >= 1 ? 5 : 0;

        $raw = $imageScore + $mainImageScore + $videoScore;
        // scale to $maxPoints (raw max = 25)
        return (int) round($maxPoints * ($raw / 25));
    }
}
