<?php

namespace App\Services\Seo;

use App\Models\AutoOptimizeItem;
use App\Models\SeoBioBatchRow;
use App\Models\SeoBioFeedback;
use Illuminate\Support\Collection;

class BioUniquenessAnalyzer
{
    private const DEFAULT_LOOKBACK_DAYS = 60;
    private const MAX_CORPUS_ROWS = 300;

    private const STOP_WORDS = [
        'about', 'after', 'again', 'also', 'and', 'around', 'available', 'based',
        'because', 'been', 'before', 'being', 'between', 'book', 'booking', 'but',
        'can', 'city', 'contact', 'does', 'each', 'escort', 'feel', 'from', 'give',
        'good', 'have', 'here', 'into', 'just', 'like', 'looking', 'make', 'more',
        'most', 'need', 'offers', 'only', 'profile', 'ready', 'real', 'same', 'she',
        'that', 'the', 'them', 'then', 'there', 'this', 'time', 'very', 'when',
        'with', 'will', 'you', 'your',
    ];

    private const AI_SLOP_PATTERNS = [
        'no_no_punchline' => [
            '/\bno\s+[^.!?]{2,40},\s+no\s+[^.!?]{2,40}\.\s+(?:just|only)\b/i',
            '/\bno\s+[^.!?]{2,40},\s+no\s+[^.!?]{2,40}\b/i',
        ],
        'negative_parallelism' => [
            '/\bnot only\b.+\bbut also\b/i',
            '/\bnot just\b.+\bbut also\b/i',
            '/\brather than\b/i',
        ],
        'stock_seduction' => [
            '/\bkeep(?:s|ing)?\s+things\s+(?:simple|real|easy|honest|straightforward)\b/i',
            '/\bno games\b/i',
            '/\bno wasting time\b/i',
            '/\bno rush\b/i',
            '/\bno pressure\b/i',
            '/\bno scripts\b/i',
            '/\bfake attitude\b/i',
            '/\bshe is the one\b/i',
            '/\bsets? the pace\b/i',
            '/\breads? you well\b/i',
            '/\bon (?:his|your) toes\b/i',
            '/\band so is (?:the|her|his)\b/i',
            '/\bbetter vibe\b/i',
            '/\b(?:real|genuine)\s+connection\b/i',
            '/\bgood conversation\b/i',
            '/\bdown[- ]to[- ]earth\b/i',
            '/\beasy to talk to\b/i',
            '/\bsee if (?:we|it) clicks?\b/i',
            '/\bknows? what (?:she|i) want\b/i',
            '/\bschedule is flexible\b/i',
            '/\bthe rest is where things get interesting\b/i',
        ],
        'puffery' => [
            '/\bserves as\b/i',
            '/\bstands as\b/i',
            '/\btestament\b/i',
            '/\bunderscore[sd]?\b/i',
            '/\bshowcas(?:e|es|ing)\b/i',
            '/\bvibrant\b/i',
            '/\btapestry\b/i',
            '/\bpivotal\b/i',
            '/\bprofound\b/i',
            '/\bdiverse array\b/i',
            '/\bin the heart of\b/i',
        ],
        'formatting_artifact' => [
            '/(^|\n)\s{0,3}#{1,6}\s+/m',
            '/(^|\n)\s*[-*]\s+\w+/m',
            '/\*\*[^*]+\*\*/',
            '/[\x{2018}\x{2019}\x{201C}\x{201D}\x{2013}\x{2014}]/u',
            '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u',
        ],
    ];

    public function analyze(string $bioHtml, int $platformId, array $options = []): array
    {
        $text = $this->plainText($bioHtml);
        $ignored = $this->ignoredTerms($options['ignored_overuse_terms'] ?? []);
        $lookbackDays = $this->lookbackDays($options['overuse_lookback_days'] ?? null);
        $sensitivity = $this->sensitivity((string) ($options['overuse_sensitivity'] ?? 'medium'));

        $corpus = $this->corpus($platformId, $lookbackDays);
        $overuse = $this->overuseScore($text, $corpus, $ignored, $sensitivity);
        $slop = $this->aiSlopScore($text);

        $uniqueness = max(0, 100 - (int) round(($overuse['score'] * 0.65) + ($slop['score'] * 0.35)));

        return [
            'overuse_score' => $overuse['score'],
            'overuse_flags' => $overuse['flags'],
            'corpus_sample_size' => $corpus->count(),
            'ai_slop_score' => $slop['score'],
            'ai_slop_flags' => $slop['flags'],
            'bio_uniqueness_score' => $uniqueness,
        ];
    }

    public function shouldRewrite(array $analysis, array $options = []): bool
    {
        $threshold = match ($this->sensitivity((string) ($options['overuse_sensitivity'] ?? 'medium'))) {
            'low' => 78,
            'high' => 52,
            default => 64,
        };

        $overuseHigh = (int) ($analysis['corpus_sample_size'] ?? 0) >= 3
            && (int) ($analysis['overuse_score'] ?? 0) >= $threshold;

        return $overuseHigh || (int) ($analysis['ai_slop_score'] ?? 0) >= 55;
    }

    public function rewriteInstruction(array $analysis): string
    {
        $flags = collect(array_merge(
            (array) ($analysis['overuse_flags'] ?? []),
            (array) ($analysis['ai_slop_flags'] ?? []),
        ))
            ->map(fn (array $flag): string => (string) ($flag['label'] ?? $flag['term'] ?? $flag['pattern'] ?? 'formulaic wording'))
            ->filter()
            ->unique()
            ->take(8)
            ->implode(', ');

        $flags = $flags !== '' ? $flags : 'repeated wording and formulaic rhythm';

        return "Rewrite the draft once to improve uniqueness. Preserve the facts, sensual human voice, language, and target length. Change the opening angle, sentence rhythm, verbs, and paragraph shape. Do not use name/age as the opener. Avoid these flagged patterns: {$flags}.";
    }

    private function overuseScore(string $text, Collection $corpus, array $ignored, string $sensitivity): array
    {
        if ($corpus->count() < 3 || trim($text) === '') {
            return ['score' => 0, 'flags' => []];
        }

        $stats = [
            'word' => [],
            'phrase' => [],
            'opening' => [],
        ];

        foreach ($corpus as $sample) {
            $sampleText = $this->plainText((string) $sample);
            foreach (array_unique($this->words($sampleText, $ignored)) as $word) {
                $stats['word'][$word] = ($stats['word'][$word] ?? 0) + 1;
            }
            foreach (array_unique($this->phrases($sampleText, $ignored)) as $phrase) {
                $stats['phrase'][$phrase] = ($stats['phrase'][$phrase] ?? 0) + 1;
            }
            foreach (array_unique($this->sentenceOpenings($sampleText, $ignored)) as $opening) {
                $stats['opening'][$opening] = ($stats['opening'][$opening] ?? 0) + 1;
            }
        }

        $thresholds = match ($sensitivity) {
            'low' => ['word' => 0.34, 'phrase' => 0.20, 'opening' => 0.16],
            'high' => ['word' => 0.18, 'phrase' => 0.10, 'opening' => 0.08],
            default => ['word' => 0.25, 'phrase' => 0.14, 'opening' => 0.11],
        };

        $weights = ['word' => 5, 'phrase' => 13, 'opening' => 15];
        $current = [
            'word' => array_count_values($this->words($text, $ignored)),
            'phrase' => array_count_values($this->phrases($text, $ignored)),
            'opening' => array_count_values($this->sentenceOpenings($text, $ignored)),
        ];

        $flags = [];
        $score = 0;
        $sampleSize = max(1, $corpus->count());

        foreach ($current as $type => $items) {
            foreach ($items as $term => $uses) {
                $corpusHits = (int) ($stats[$type][$term] ?? 0);
                $rate = $corpusHits / $sampleSize;
                if ($corpusHits < 2 || $rate < $thresholds[$type]) {
                    continue;
                }

                $impact = min(22, (int) round($weights[$type] * $uses * (1 + ($rate * 2))));
                $score += $impact;
                $flags[] = [
                    'type' => $type,
                    'term' => $term,
                    'label' => "{$type}: {$term}",
                    'current_uses' => $uses,
                    'corpus_hits' => $corpusHits,
                    'corpus_rate' => round($rate, 3),
                    'impact' => $impact,
                ];
            }
        }

        usort($flags, fn (array $a, array $b): int => ($b['impact'] ?? 0) <=> ($a['impact'] ?? 0));

        return [
            'score' => min(100, $score),
            'flags' => array_slice($flags, 0, 12),
        ];
    }

    private function aiSlopScore(string $text): array
    {
        $flags = [];
        $score = 0;

        foreach (self::AI_SLOP_PATTERNS as $type => $patterns) {
            foreach ($patterns as $pattern) {
                if (!preg_match_all($pattern, $text, $matches)) {
                    continue;
                }

                $count = count($matches[0]);
                $impact = min(42, $count * match ($type) {
                    'formatting_artifact' => 18,
                    'no_no_punchline' => 36,
                    'stock_seduction' => 20,
                    default => 14,
                });
                $score += $impact;
                $flags[] = [
                    'type' => $type,
                    'pattern' => $pattern,
                    'label' => str_replace('_', ' ', $type),
                    'current_uses' => $count,
                    'impact' => $impact,
                ];
            }
        }

        if (preg_match_all('/\b[a-z]{4,}\b,\s+\b[a-z]{4,}\b,\s+(and\s+)?\b[a-z]{4,}\b/i', $text, $matches)) {
            $impact = min(24, count($matches[0]) * 12);
            $score += $impact;
            $flags[] = [
                'type' => 'rule_of_three',
                'pattern' => 'three-part adjective/list rhythm',
                'label' => 'rule of three rhythm',
                'current_uses' => count($matches[0]),
                'impact' => $impact,
            ];
        }

        usort($flags, fn (array $a, array $b): int => ($b['impact'] ?? 0) <=> ($a['impact'] ?? 0));

        return [
            'score' => min(100, $score),
            'flags' => array_slice($flags, 0, 10),
        ];
    }

    private function corpus(int $platformId, int $lookbackDays): Collection
    {
        $since = now()->subDays($lookbackDays);
        $samples = collect();

        try {
            $samples = $samples->merge(
                SeoBioFeedback::query()
                    ->where('platform_id', $platformId)
                    ->where('created_at', '>=', $since)
                    ->whereNotNull('bio_html')
                    ->where(function ($query) {
                        $query->where('accepted', true)->orWhere('rating', '>=', 0);
                    })
                    ->latest()
                    ->limit(self::MAX_CORPUS_ROWS)
                    ->pluck('bio_html')
            );
        } catch (\Throwable) {
            // Local installs may be mid-migration; generation should still work.
        }

        try {
            $samples = $samples->merge(
                SeoBioBatchRow::query()
                    ->whereHas('batch', fn ($query) => $query->where('platform_id', $platformId))
                    ->whereIn('status', [SeoBioBatchRow::STATUS_GENERATED, SeoBioBatchRow::STATUS_ACCEPTED])
                    ->where('updated_at', '>=', $since)
                    ->whereNotNull('bio_html')
                    ->latest()
                    ->limit(self::MAX_CORPUS_ROWS)
                    ->pluck('bio_html')
            );
        } catch (\Throwable) {
        }

        try {
            $samples = $samples->merge(
                AutoOptimizeItem::query()
                    ->where('platform_id', $platformId)
                    ->where('updated_at', '>=', $since)
                    ->whereNotNull('new_bio_html')
                    ->latest()
                    ->limit(self::MAX_CORPUS_ROWS)
                    ->pluck('new_bio_html')
            );
        } catch (\Throwable) {
        }

        return $samples
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique(fn (string $value): string => md5($this->plainText($value)))
            ->take(self::MAX_CORPUS_ROWS)
            ->values();
    }

    private function words(string $text, array $ignored): array
    {
        preg_match_all('/[a-z][a-z\']{3,}/i', strtolower($text), $matches);

        return array_values(array_filter($matches[0] ?? [], function (string $word) use ($ignored): bool {
            $word = trim($word, "'");
            return strlen($word) >= 4
                && !isset(self::STOP_WORDS[$word])
                && !in_array($word, self::STOP_WORDS, true)
                && !isset($ignored[$word]);
        }));
    }

    private function phrases(string $text, array $ignored): array
    {
        $words = $this->words($text, $ignored);
        $phrases = [];

        for ($i = 0; $i < count($words) - 1; $i++) {
            $phrases[] = $words[$i] . ' ' . $words[$i + 1];
        }
        for ($i = 0; $i < count($words) - 2; $i++) {
            $phrases[] = $words[$i] . ' ' . $words[$i + 1] . ' ' . $words[$i + 2];
        }

        return $phrases;
    }

    private function sentenceOpenings(string $text, array $ignored): array
    {
        $sentences = preg_split('/[.!?]+/', $text) ?: [];
        $openings = [];

        foreach ($sentences as $sentence) {
            $words = $this->words($sentence, $ignored);
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

    private function ignoredTerms(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);

        return collect($items)
            ->flatMap(fn ($item) => preg_split('/[\n,]+/', (string) $item) ?: [])
            ->map(fn ($item) => strtolower(trim((string) $item)))
            ->filter()
            ->mapWithKeys(fn ($item) => [$item => true])
            ->all();
    }

    private function lookbackDays(mixed $value): int
    {
        return max(7, min(365, (int) ($value ?: self::DEFAULT_LOOKBACK_DAYS)));
    }

    private function sensitivity(string $value): string
    {
        return in_array($value, ['low', 'medium', 'high'], true) ? $value : 'medium';
    }
}
