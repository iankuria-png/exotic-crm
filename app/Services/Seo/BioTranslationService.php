<?php

namespace App\Services\Seo;

use App\Services\Seo\Exceptions\AllProvidersFailedException;
use App\Services\Seo\Llm\ProviderWaterfall;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Translates an already-generated bio into English for editorial review.
 *
 * Cached for 24h by content hash so toggling Show English ⇄ Hide English on the
 * same bio doesn't burn LLM credits, and so the editor can flip back and
 * forth without any latency.
 *
 * Strategy:
 *   - Strips HTML before sending to the LLM (smaller prompt, fewer tokens)
 *   - System prompt is intentionally narrow: translate, don't editorialise
 *   - Re-wraps each paragraph in <p> on the way out so the modal can render
 *     it with the same prose styles as the original
 */
class BioTranslationService
{
    private const CACHE_TTL = 86_400; // 24h

    public function __construct(private readonly BioGenerationService $generator) {}

    /**
     * @param  string  $bioHtml      The non-English bio HTML to translate.
     * @param  string  $fromLanguage Source language code (fr, pt, sw…). Used as a hint only.
     * @return array{translation_html: string, provider_used: string, cached: bool}
     */
    public function translateToEnglish(string $bioHtml, string $fromLanguage): array
    {
        $plain = trim(strip_tags($bioHtml));
        if ($plain === '') {
            return [
                'translation_html' => '',
                'provider_used'    => 'noop',
                'cached'           => false,
            ];
        }

        $cacheKey = 'seo_translation_en_' . md5($fromLanguage . '|' . $plain);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return array_merge($cached, ['cached' => true]);
        }

        try {
            $waterfall = ProviderWaterfall::fromConfig(null);
            $response = $waterfall->generate(
                $this->buildSystemPrompt($fromLanguage),
                $this->buildUserPrompt($plain),
                ['max_tokens' => 800],
            );
            $translated = trim($response->text);
        } catch (AllProvidersFailedException $e) {
            Log::warning('seo.translation.providers_failed', [
                'from_language' => $fromLanguage,
                'reason' => $e->getMessage(),
            ]);
            return [
                'translation_html' => '<p><em>Translation unavailable: no LLM provider is currently reachable.</em></p>',
                'provider_used'    => 'unavailable',
                'cached'           => false,
            ];
        }

        $html = $this->textToHtml($translated);
        $result = [
            'translation_html' => $html,
            'provider_used'    => $response->provider,
        ];

        Cache::put($cacheKey, $result, self::CACHE_TTL);

        return array_merge($result, ['cached' => false]);
    }

    // -----------------------------------------------------------------

    private function buildSystemPrompt(string $fromLanguage): string
    {
        $languages = BioGenerationService::SUPPORTED_LANGUAGES;
        $sourceLabel = $languages[$fromLanguage]['label'] ?? 'the source language';

        return <<<PROMPT
You are a faithful translator. Translate the user's text from {$sourceLabel} into clear, natural English.
Rules:
- Preserve meaning exactly. Do NOT add, omit, or paraphrase beyond what English idiom requires.
- Preserve paragraph breaks.
- Preserve any phone numbers, URLs, or proper nouns exactly as written.
- Output plain prose only. No markdown, no headings, no notes, no quotation marks around the translation.
- Use plain ASCII punctuation (', ", -, ...). No curly quotes or em-dashes.
PROMPT;
    }

    private function buildUserPrompt(string $text): string
    {
        return "Translate this to English:\n\n" . $text;
    }

    private function textToHtml(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $paragraphs = preg_split('/\n{2,}/', $text) ?: [$text];
        $html = '';
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para !== '') {
                $html .= '<p>' . nl2br(htmlspecialchars($para, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
            }
        }
        return $html;
    }
}
