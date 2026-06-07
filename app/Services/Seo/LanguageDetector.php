<?php

namespace App\Services\Seo;

class LanguageDetector
{
    private const SIGNATURES = [
        'en' => [
            'stopwords' => ['the', 'and', 'with', 'for', 'you', 'your', 'in', 'from', 'near', 'available'],
            'markers' => [],
        ],
        'fr' => [
            'stopwords' => ['avec', 'pour', 'dans', 'vous', 'votre', 'une', 'des', 'les', 'et', 'bonjour'],
            'markers' => ['bonjour', 'francaise', 'francais', 'discrete', 'sensuelle'],
            'diacritics' => '/[àâçéèêëîïôùûüÿœ]/u',
        ],
        'pt' => [
            'stopwords' => ['com', 'para', 'voce', 'uma', 'das', 'dos', 'mais', 'em', 'ola', 'atendimento'],
            'markers' => ['olá', 'voce', 'prazer', 'discreta', 'portugues'],
            'diacritics' => '/[ãáàâçéêíóôõú]/u',
        ],
        'sw' => [
            'stopwords' => ['na', 'kwa', 'karibu', 'huduma', 'wako', 'sana', 'yako', 'ya', 'za', 'wenye'],
            'markers' => ['karibu', 'kiswahili', 'mrembo', 'huduma', 'wateja'],
            'diacritics' => null,
        ],
    ];

    public function detect(string $text): array
    {
        $normalized = $this->normalize($text);
        if ($normalized === '') {
            return ['language' => 'en', 'confidence' => 0.0];
        }

        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return ['language' => 'en', 'confidence' => 0.0];
        }

        $scores = [];
        foreach (self::SIGNATURES as $language => $signature) {
            $score = 0.0;
            $tokenCounts = array_count_values($tokens);

            foreach ($signature['stopwords'] as $word) {
                $score += (float) ($tokenCounts[$word] ?? 0);
            }

            foreach ($signature['markers'] ?? [] as $word) {
                if (str_contains($normalized, $this->normalize($word))) {
                    $score += 1.5;
                }
            }

            $pattern = $signature['diacritics'] ?? null;
            if (is_string($pattern) && preg_match_all($pattern, mb_strtolower($text), $matches)) {
                $score += count($matches[0]) * 0.8;
            }

            $scores[$language] = $score;
        }

        arsort($scores);
        $language = (string) array_key_first($scores);
        $topScore = (float) ($scores[$language] ?? 0.0);
        $runnerUp = (float) collect(array_values($scores))->skip(1)->first();

        if ($topScore <= 0.0) {
            return ['language' => 'en', 'confidence' => 0.0];
        }

        $confidence = min(1.0, max(
            0.2,
            ($topScore + max(0.0, $topScore - $runnerUp)) / max(3.0, count($tokens) * 0.45)
        ));

        return [
            'language' => $language,
            'confidence' => round($confidence, 3),
        ];
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = str_replace(
            ['à', 'á', 'â', 'ã', 'ä', 'ç', 'è', 'é', 'ê', 'ë', 'ì', 'í', 'î', 'ï', 'ò', 'ó', 'ô', 'õ', 'ö', 'ù', 'ú', 'û', 'ü', 'ÿ', 'œ'],
            ['a', 'a', 'a', 'a', 'a', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'y', 'oe'],
            $text
        );
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
