<?php

namespace App\Services\Seo;

use App\Models\Client;
use App\Models\Platform;
use App\Models\SeoBioFeedback;
use Illuminate\Support\Collection;

class BioQualityAuditService
{
    private const DEFAULT_LIMIT = 300;

    private const STOP_WORDS = [
        'about', 'after', 'again', 'also', 'and', 'around', 'available', 'based',
        'because', 'been', 'before', 'being', 'between', 'book', 'booking', 'but',
        'can', 'city', 'contact', 'does', 'each', 'escort', 'feel', 'from', 'give',
        'good', 'have', 'here', 'into', 'just', 'like', 'looking', 'make', 'more',
        'most', 'need', 'offers', 'only', 'profile', 'ready', 'real', 'same', 'she',
        'that', 'the', 'them', 'then', 'there', 'this', 'time', 'very', 'when',
        'with', 'will', 'you', 'your',
    ];

    private const SLOP_PATTERNS = [
        'no_no_punchline' => [
            '/\bno\s+[^.!?]{2,40},\s+no\s+[^.!?]{2,40}\.\s+(?:just|only)\b/i',
            '/\bno\s+[^.!?]{2,40},\s+no\s+[^.!?]{2,40}\b/i',
        ],
        'corpus_cliche' => [
            '/\bkeep(?:s|ing)?\s+things\s+(?:simple|real|easy|honest|straightforward)\b/i',
            '/\bno games\b/i',
            '/\bno rush\b/i',
            '/\bno pressure\b/i',
            '/\bno scripts\b/i',
            '/\bno fake attitude\b/i',
            '/\bjust\s+(?:good|easy|better|real)\s+(?:company|vibes?|energy|pleasure)\b/i',
            '/\b(?:real|genuine)\s+connection\b/i',
            '/\bgood conversation\b/i',
            '/\bdown[- ]to[- ]earth\b/i',
            '/\beasy to talk to\b/i',
            '/\bsee if (?:we|it) clicks?\b/i',
            '/\bknows? what (?:she|i) want\b/i',
            '/\bthe rest is where things get interesting\b/i',
        ],
        'format_artifact' => [
            '/[\x{2018}\x{2019}\x{201C}\x{201D}\x{2013}\x{2014}]/u',
            '/\s[-–—]{1,2}\s/u',
            '/(^|\n)\s{0,3}#{1,6}\s+/m',
            '/(^|\n)\s*[-*]\s+\w+/m',
            '/\*\*[^*]+\*\*/',
        ],
        'over_polished' => [
            '/\bsophisticated presence\b/i',
            '/\bnatural elegance\b/i',
            '/\bcommand(?:s)? attention\b/i',
            '/\bunforgettable experience\b/i',
            '/\bquality over quantity\b/i',
            '/\bcaptivating presence\b/i',
            '/\bideal companion\b/i',
            '/\bin the heart of\b/i',
        ],
    ];

    public function scan(?int $platformId = null, string $source = 'all', int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(25, min(1000, $limit));
        $source = in_array($source, ['all', 'live', 'generated', 'accepted'], true) ? $source : 'all';

        if ($platformId) {
            $platform = Platform::query()->find($platformId);

            return [
                'source' => $source,
                'limit' => $limit,
                'summary' => $this->scorePlatform($platformId, $source, $limit, $platform),
                'platforms' => [],
            ];
        }

        $platforms = Platform::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'country', 'domain']);

        $rows = $platforms
            ->map(fn (Platform $platform): array => $this->scorePlatform((int) $platform->id, $source, min($limit, 250), $platform))
            ->filter(fn (array $row): bool => (int) ($row['sample_size'] ?? 0) > 0)
            ->sortBy('quality_score')
            ->values()
            ->all();

        return [
            'source' => $source,
            'limit' => $limit,
            'summary' => $this->combine($rows),
            'platforms' => $rows,
        ];
    }

    private function scorePlatform(int $platformId, string $source, int $limit, ?Platform $platform = null): array
    {
        $samples = $this->samples($platformId, $source, $limit);
        $texts = $samples->pluck('text')->values();
        $sampleSize = $texts->count();

        if ($sampleSize === 0) {
            return $this->emptySummary($platformId, $platform, $source);
        }

        $metrics = [
            'name_intro_rate' => 0,
            'age_mention_rate' => 0,
            'ethnicity_mention_rate' => 0,
            'dash_punctuation_rate' => 0,
            'thin_bio_rate' => 0,
        ];
        $wordCounts = [];
        $slopFlags = [];
        $wordDocs = [];
        $phraseDocs = [];
        $openingDocs = [];
        $examples = [];

        foreach ($samples as $sample) {
            $text = (string) $sample['text'];
            $lower = mb_strtolower($text);
            $words = $this->words($text);
            $wordCounts[] = count($words);

            $metrics['name_intro_rate'] += preg_match('/^\s*(?:i am|i\'m|im|meet|my name is)\s+[a-z]{2,}\b/i', $text) ? 1 : 0;
            $metrics['age_mention_rate'] += preg_match('/\b\d{2}\s*(?:year|yr)|\b\d{2}\s*,/i', $text) ? 1 : 0;
            $metrics['ethnicity_mention_rate'] += preg_match('/\b(?:black|african|latin|white|asian|mixed race)\b/i', $lower) ? 1 : 0;
            $metrics['dash_punctuation_rate'] += preg_match('/\s[-–—]{1,2}\s/u', $text) ? 1 : 0;
            $metrics['thin_bio_rate'] += count($words) < 55 ? 1 : 0;

            foreach (array_unique($words) as $word) {
                $wordDocs[$word] = ($wordDocs[$word] ?? 0) + 1;
            }
            foreach (array_unique($this->phrases($words)) as $phrase) {
                $phraseDocs[$phrase] = ($phraseDocs[$phrase] ?? 0) + 1;
            }
            foreach (array_unique($this->sentenceOpenings($text)) as $opening) {
                $openingDocs[$opening] = ($openingDocs[$opening] ?? 0) + 1;
            }

            foreach ($this->slopFlags($text) as $flag) {
                $key = $flag['label'];
                $slopFlags[$key] = ($slopFlags[$key] ?? 0) + (int) $flag['count'];
                if (count($examples) < 8) {
                    $examples[] = [
                        'client_id' => $sample['client_id'] ?? null,
                        'feedback_id' => $sample['feedback_id'] ?? null,
                        'issue' => $flag['label'],
                        'snippet' => mb_substr($text, 0, 220),
                    ];
                }
            }
        }

        foreach ($metrics as $key => $value) {
            $metrics[$key] = round($value / $sampleSize, 3);
        }

        $repetitionFlags = array_merge(
            $this->topRepeated($phraseDocs, $sampleSize, 'phrase', 0.09),
            $this->topRepeated($openingDocs, $sampleSize, 'opening', 0.04),
            $this->topRepeated($wordDocs, $sampleSize, 'word', 0.28),
        );
        usort($repetitionFlags, fn (array $a, array $b): int => ($b['impact'] ?? 0) <=> ($a['impact'] ?? 0));

        $slopScore = min(100, array_sum($slopFlags) * 3);
        $repetitionScore = min(100, array_sum(array_column($repetitionFlags, 'impact')));
        $structureScore = min(100, (int) round(
            ($metrics['name_intro_rate'] * 22)
            + ($metrics['age_mention_rate'] * 20)
            + ($metrics['ethnicity_mention_rate'] * 28)
            + ($metrics['dash_punctuation_rate'] * 16)
            + ($metrics['thin_bio_rate'] * 18)
        ));
        $aiLikenessScore = min(100, (int) round(($slopScore * 0.5) + ($repetitionScore * 0.3) + ($structureScore * 0.2)));
        $qualityScore = max(0, 100 - $aiLikenessScore);

        sort($wordCounts);

        return [
            'platform_id' => $platformId,
            'platform_name' => $platform?->name,
            'country' => $platform?->country,
            'domain' => $platform?->domain,
            'source' => $source,
            'sample_size' => $sampleSize,
            'quality_score' => $qualityScore,
            'quality_band' => $this->band($qualityScore),
            'ai_likeness_score' => $aiLikenessScore,
            'repetition_score' => $repetitionScore,
            'slop_score' => $slopScore,
            'structure_score' => $structureScore,
            'avg_words' => round(array_sum($wordCounts) / $sampleSize, 1),
            'median_words' => $wordCounts[(int) floor($sampleSize / 2)] ?? 0,
            'metrics' => $metrics,
            'top_repetition_flags' => array_slice($repetitionFlags, 0, 12),
            'top_slop_flags' => $this->topFlags($slopFlags, $sampleSize),
            'examples' => $examples,
        ];
    }

    private function samples(int $platformId, string $source, int $limit): Collection
    {
        $rows = collect();

        if (in_array($source, ['all', 'live'], true)) {
            $rows = $rows->merge(
                Client::query()
                    ->where('platform_id', $platformId)
                    ->whereNotNull('bio_original_html')
                    ->where('bio_original_html', '<>', '')
                    ->latest('updated_at')
                    ->limit($limit)
                    ->get(['id', 'bio_original_html'])
                    ->map(fn (Client $client): array => [
                        'client_id' => (int) $client->id,
                        'text' => $this->plainText((string) $client->bio_original_html),
                    ])
            );
        }

        if (in_array($source, ['all', 'generated', 'accepted'], true)) {
            $query = SeoBioFeedback::query()
                ->where('platform_id', $platformId)
                ->whereNotNull('bio_html')
                ->where('bio_html', '<>', '')
                ->latest('created_at');

            if ($source === 'accepted') {
                $query->where('accepted', true);
            }

            $rows = $rows->merge(
                $query
                    ->limit($limit)
                    ->get(['id', 'client_id', 'bio_html'])
                    ->map(fn (SeoBioFeedback $feedback): array => [
                        'feedback_id' => (int) $feedback->id,
                        'client_id' => $feedback->client_id,
                        'text' => $this->plainText((string) $feedback->bio_html),
                    ])
            );
        }

        return $rows
            ->filter(fn (array $row): bool => mb_strlen((string) $row['text']) > 20)
            ->unique(fn (array $row): string => md5((string) $row['text']))
            ->take($limit)
            ->values();
    }

    private function combine(array $rows): array
    {
        if ($rows === []) {
            return [
                'sample_size' => 0,
                'quality_score' => 0,
                'quality_band' => 'none',
                'ai_likeness_score' => 0,
            ];
        }

        $sampleSize = array_sum(array_map(fn (array $row): int => (int) $row['sample_size'], $rows));
        $weighted = function (string $key) use ($rows, $sampleSize): int {
            if ($sampleSize <= 0) {
                return 0;
            }

            return (int) round(array_sum(array_map(
                fn (array $row): int|float => ((int) $row['sample_size']) * ((int) ($row[$key] ?? 0)),
                $rows
            )) / $sampleSize);
        };

        $qualityScore = $weighted('quality_score');

        return [
            'sample_size' => $sampleSize,
            'quality_score' => $qualityScore,
            'quality_band' => $this->band($qualityScore),
            'ai_likeness_score' => $weighted('ai_likeness_score'),
            'repetition_score' => $weighted('repetition_score'),
            'slop_score' => $weighted('slop_score'),
            'structure_score' => $weighted('structure_score'),
        ];
    }

    private function emptySummary(int $platformId, ?Platform $platform, string $source): array
    {
        return [
            'platform_id' => $platformId,
            'platform_name' => $platform?->name,
            'country' => $platform?->country,
            'domain' => $platform?->domain,
            'source' => $source,
            'sample_size' => 0,
            'quality_score' => 0,
            'quality_band' => 'none',
            'ai_likeness_score' => 0,
            'top_repetition_flags' => [],
            'top_slop_flags' => [],
            'examples' => [],
            'metrics' => [],
        ];
    }

    private function slopFlags(string $text): array
    {
        $flags = [];
        foreach (self::SLOP_PATTERNS as $type => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $text, $matches)) {
                    $flags[] = [
                        'type' => $type,
                        'label' => str_replace('_', ' ', $type),
                        'count' => count($matches[0]),
                    ];
                }
            }
        }

        return $flags;
    }

    private function topRepeated(array $docs, int $sampleSize, string $type, float $threshold): array
    {
        $flags = [];
        foreach ($docs as $term => $hits) {
            $rate = $hits / max(1, $sampleSize);
            if ($hits < 3 || $rate < $threshold) {
                continue;
            }

            $flags[] = [
                'type' => $type,
                'term' => $term,
                'label' => "{$type}: {$term}",
                'corpus_hits' => $hits,
                'corpus_rate' => round($rate, 3),
                'impact' => min(25, (int) round($rate * match ($type) {
                    'opening' => 90,
                    'phrase' => 70,
                    default => 35,
                })),
            ];
        }

        return $flags;
    }

    private function topFlags(array $flags, int $sampleSize): array
    {
        arsort($flags);

        return collect($flags)
            ->map(fn (int $count, string $label): array => [
                'label' => $label,
                'count' => $count,
                'rate' => round($count / max(1, $sampleSize), 3),
            ])
            ->values()
            ->take(10)
            ->all();
    }

    private function words(string $text): array
    {
        preg_match_all('/[a-z][a-z\']{3,}/i', mb_strtolower($text), $matches);

        return array_values(array_filter($matches[0] ?? [], function (string $word): bool {
            $word = trim($word, "'");

            return mb_strlen($word) >= 4
                && !in_array($word, self::STOP_WORDS, true);
        }));
    }

    private function phrases(array $words): array
    {
        $phrases = [];

        for ($i = 0; $i < count($words) - 1; $i++) {
            $phrases[] = $words[$i] . ' ' . $words[$i + 1];
        }
        for ($i = 0; $i < count($words) - 2; $i++) {
            $phrases[] = $words[$i] . ' ' . $words[$i + 1] . ' ' . $words[$i + 2];
        }

        return $phrases;
    }

    private function sentenceOpenings(string $text): array
    {
        $sentences = preg_split('/[.!?]+/', $text) ?: [];
        $openings = [];

        foreach ($sentences as $sentence) {
            $words = $this->words($sentence);
            if (count($words) >= 2) {
                $openings[] = implode(' ', array_slice($words, 0, min(3, count($words))));
            }
        }

        return $openings;
    }

    private function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    private function band(int $score): string
    {
        return match (true) {
            $score >= 80 => 'strong',
            $score >= 60 => 'watch',
            $score >= 40 => 'weak',
            default => 'poor',
        };
    }
}
