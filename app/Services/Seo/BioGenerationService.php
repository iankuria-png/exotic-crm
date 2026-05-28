<?php

namespace App\Services\Seo;

use App\Models\Platform;
use App\Services\Seo\Exceptions\AllProvidersFailedException;
use App\Services\Seo\Llm\ProviderWaterfall;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates bio generation:
 *  1. Build ProfileSnapshot
 *  2. Try LLM waterfall
 *  3. Fall back to TemplateFallbackEngine
 *  4. Inject internal links (DOM-aware)
 *  5. Score final HTML
 *  6. Return {bio_html, score, breakdown, provider_used, usage}
 */
class BioGenerationService
{
    private const DEFAULT_GENERATION = [
        'tone' => 'simple, direct, local classified profile copy',
        'temperament' => 'confident but not exaggerated',
        'min_words' => 55,
        'max_words' => 95,
        'max_characters' => 750,
        'max_services' => 5,
        'include_location' => true,
        'include_services' => true,
        'include_contact' => true,
        'contact_channel' => 'whatsapp',
        'custom_prompt' => '',
        'language' => 'en',
    ];

    /**
     * Supported output languages → display label + LLM directive.
     * Add a language by extending this map; the prompt builder picks the
     * directive automatically.
     */
    public const SUPPORTED_LANGUAGES = [
        'en' => [
            'label'     => 'English',
            'directive' => 'Write the entire bio in natural, idiomatic English.',
        ],
        'fr' => [
            'label'     => 'French',
            'directive' => 'Write the entire bio in natural, idiomatic French (français). Use standard punctuation. No mixed languages.',
        ],
        'pt' => [
            'label'     => 'Portuguese',
            'directive' => 'Write the entire bio in natural, idiomatic Portuguese (português). Use standard punctuation. No mixed languages.',
        ],
        'sw' => [
            'label'     => 'Swahili',
            'directive' => 'Write the entire bio in natural, conversational Swahili (Kiswahili) that local readers will understand. Avoid Sheng/slang. No mixed languages.',
        ],
    ];

    /**
     * Quick-action refinements that the preview modal exposes as chips.
     * Each refinement mutates generation_options before the second-pass call.
     */
    public const REFINEMENT_PRESETS = [
        'longer' => [
            'label' => 'Make it longer',
            'min_words_delta' => 30,
            'max_words_delta' => 50,
            'max_chars_delta' => 250,
            'prompt_addendum' => 'Lengthen the previous bio with more grounded details; do not pad with filler.',
        ],
        'shorter' => [
            'label' => 'Make it shorter',
            'min_words_delta' => -20,
            'max_words_delta' => -30,
            'max_chars_delta' => -200,
            'prompt_addendum' => 'Tighten the previous bio. Cut redundant phrases. Keep the strongest sentence.',
        ],
        'more_creative' => [
            'label' => 'More creative',
            'prompt_addendum' => 'Be more imaginative and varied. Use fresher verbs and unexpected sentence rhythm. Stay factual.',
        ],
        'less_generic' => [
            'label' => 'Less generic',
            'prompt_addendum' => 'Cut all stock copywriting phrases. Use the specific profile facts to make each sentence unmistakable for this person.',
        ],
        'more_direct' => [
            'label' => 'More direct',
            'prompt_addendum' => 'Short, declarative sentences. Drop embellishment. Read like a classified listing.',
        ],
        'warmer' => [
            'label' => 'Warmer tone',
            'prompt_addendum' => 'Add warmth and personality. Use friendlier phrasing without becoming sentimental.',
        ],
        'different_angle' => [
            'label' => 'Different angle',
            'prompt_addendum' => 'Take a different angle from the previous draft. Lead with a different fact.',
        ],
    ];

    /** USD per 1M tokens. DeepSeek defaults use cache-miss input pricing. */
    private const PROVIDER_PRICING = [
        'deepseek' => ['input' => 0.27, 'output' => 1.10],
        'gemini' => ['input' => 0.10, 'output' => 0.40],
        'openai' => ['input' => 0.15, 'output' => 0.60],
        'claude' => ['input' => 3.00, 'output' => 15.00],
        'template_fallback' => ['input' => 0.00, 'output' => 0.00],
    ];

    public function __construct(
        private readonly ProfileSnapshotBuilder $snapshotBuilder,
        private readonly TemplateFallbackEngine $fallback,
        private readonly LinkInjector           $injector,
        private readonly LinkCatalogService     $catalogService,
        private readonly SeoScorer              $scorer,
        private readonly FeedbackInsightService $feedback,
    ) {}

    /**
     * Generate a bio from the canonical request shape.
     *
     * @param  array  $params  {client_id, wp_post_id, platform_id, profile_snapshot, force_provider, generation_options}
     * @return array  {bio_html, score, breakdown, provider_used, usage}
     */
    public function generate(array $params): array
    {
        $clientId      = isset($params['client_id']) ? (int) $params['client_id'] : null;
        $wpPostId      = isset($params['wp_post_id']) ? (int) $params['wp_post_id'] : null;
        $platformId    = (int) ($params['platform_id'] ?? 0);
        $overlay       = is_array($params['profile_snapshot'] ?? null) ? $params['profile_snapshot'] : null;
        $forceProvider = isset($params['force_provider']) ? (string) $params['force_provider'] : null;
        $rawOverrides  = is_array($params['generation_options'] ?? null) ? $params['generation_options'] : [];
        $refinements   = is_array($params['refinements'] ?? null) ? $params['refinements'] : [];
        $previousBio   = isset($params['previous_bio']) ? (string) $params['previous_bio'] : '';

        // Apply quick-action refinement presets on top of any explicit overrides
        $rawOverrides = $this->applyRefinements($rawOverrides, $refinements, $previousBio);
        $generationOptions = $this->generationOptions($rawOverrides);

        // Build normalized snapshot
        if ($clientId !== null || $wpPostId !== null) {
            $snapshot = $this->snapshotBuilder->fromRequest($clientId, $wpPostId, $platformId, $overlay);
            $platformId = $snapshot->platformId; // use resolved platform
        } else {
            // Preview-only: no persisted entity
            $snapshot = $this->snapshotBuilder->fromOverlayOnly($overlay ?? [], $platformId);
        }

        // Generate bio text
        [$rawText, $providerUsed, $usage] = $this->generateText($snapshot, $forceProvider, $generationOptions, $overlay ?? []);
        $rawText = $this->sanitizeOutput($rawText);
        $rawText = $this->enforceCharacterLimit($rawText, (int) $generationOptions['max_characters']);

        // Wrap in paragraphs
        $bioHtml = $this->textToHtml($rawText);
        $bioHtml = $this->linkContactNumbers($bioHtml, $overlay ?? [], $generationOptions);

        // Inject internal links. Link injection is an SEO enhancement, not a hard
        // dependency for generation; if the WP catalog or DOM parser fails in prod,
        // still return a generated bio and log the skipped enhancement.
        try {
            $catalog = $this->catalogService->forPlatform($snapshot->platformId);
            $bioHtml = $this->injector->inject($bioHtml, $catalog);
        } catch (\Throwable $e) {
            Log::warning('seo.link_injection_failed', [
                'client_id' => $clientId,
                'wp_post_id' => $wpPostId,
                'platform_id' => $snapshot->platformId,
                'error' => $e->getMessage(),
            ]);
        }

        // Score
        $scoreResult = $this->scorer->score($bioHtml, $snapshot);
        $usage = $this->withCostEstimate($providerUsed, $usage);

        Log::info('seo.bio_generated', [
            'client_id'     => $clientId,
            'wp_post_id'    => $wpPostId,
            'platform_id'   => $snapshot->platformId,
            'provider_used' => $providerUsed,
            'score'         => $scoreResult['total'],
            'estimated_cost_usd' => $usage['estimated_cost_usd'],
        ]);

        return [
            'bio_html'      => $bioHtml,
            'score'         => $scoreResult['total'],
            'breakdown'     => $scoreResult['breakdown'],
            'provider_used' => $providerUsed,
            'usage'         => $usage,
            'generation_options' => $generationOptions,
        ];
    }

    // -------------------------------------------------------------------------

    private function generateText(ProfileSnapshot $snapshot, ?string $forceProvider, array $options, array $overlay): array
    {
        $waterfall = ProviderWaterfall::fromConfig($forceProvider);

        try {
            $response = $waterfall->generate(
                $this->buildSystemPrompt($snapshot, $options),
                $this->buildUserPrompt($snapshot, $options, $overlay),
                ['max_tokens' => $this->maxTokensForOptions($options)],
            );

            return [$response->text, $response->provider, [
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'total_tokens' => $response->inputTokens + $response->outputTokens,
            ]];
        } catch (AllProvidersFailedException $e) {
            Log::notice('seo.all_providers_failed', [
                'platform_id' => $snapshot->platformId,
                'reason'      => $e->getMessage(),
            ]);
        }

        return [$this->fallback->generate($snapshot), 'template_fallback', [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
        ]];
    }

    private function buildSystemPrompt(ProfileSnapshot $snapshot, array $options): string
    {
        $platform = Platform::find($snapshot->platformId);
        $country  = $platform?->country ?? 'Kenya';
        $minWords = (int) $options['min_words'];
        $maxWords = (int) $options['max_words'];
        $maxChars = (int) $options['max_characters'];
        $tone = $options['tone'];
        $temperament = $options['temperament'];
        $custom = trim((string) $options['custom_prompt']);
        $customLine = $custom !== '' ? "\nExtra editor instruction: {$custom}" : '';
        $feedbackBlock = $this->feedback->instructionsForPlatform($snapshot->platformId);
        $langCode = (string) ($options['language'] ?? 'en');
        $langDirective = self::SUPPORTED_LANGUAGES[$langCode]['directive']
            ?? self::SUPPORTED_LANGUAGES['en']['directive'];

        return <<<PROMPT
You write short public profile copy for an adult escort directory in {$country}.
{$langDirective}
Write {$minWords}-{$maxWords} words, no more than {$maxChars} characters.
Tone: {$tone}. Temperament: {$temperament}.
Style rules:
- Write like a concise local classified/profile listing, not a luxury brochure.
- Be specific and factual. Prefer short sentences.
- Do not invent services, locations, contact details, nationality, measurements, or claims.
- Do not mention height or body measurements in the bio; these already appear in profile facts.
- Avoid generic AI phrases like "sophisticated presence", "natural elegance", "commands attention", "unforgettable experience", "mutual respect", "quality over quantity", "bustling city", "ideal companion", "captivating presence".
- No markdown, no headings, no lists, no emoji, no Unicode symbols.
- Use plain ASCII punctuation only (no curly quotes, no em-dashes — use ' " - instead).
- Return clean prose only.{$customLine}{$feedbackBlock}
PROMPT;
    }

    private function buildUserPrompt(ProfileSnapshot $snapshot, array $options, array $overlay): string
    {
        $data = [
            'Name' => $snapshot->name ?: '(not provided)',
            'Age' => $snapshot->age !== null ? $snapshot->age . ' years old' : '(not provided)',
            'Gender' => $snapshot->gender ?: 'female',
            'Ethnicity' => $snapshot->ethnicity ?? '(not specified)',
            'Build' => $snapshot->build ?? '(not specified)',
            'Languages' => !empty($snapshot->languages) ? implode(', ', $snapshot->languages) : 'English',
            'Availability' => $snapshot->availabilityText(),
        ];

        if (trim($snapshot->existingBio) !== '') {
            $data['Previous bio context'] = mb_substr(strip_tags($snapshot->existingBio), 0, 500) . ' — use only for continuity and uniqueness; do not copy phrasing.';
        }

        if ($options['include_location']) {
            $data['City'] = $snapshot->city ?: '(not provided)';
            $data['Neighborhood'] = $snapshot->neighborhood ?? '(not specified)';
        }

        if ($options['include_services']) {
            $services = array_slice($snapshot->services, 0, (int) $options['max_services']);
            $data['Services to mention'] = $services !== [] ? implode(', ', $services) : '(not specified)';
        }

        $contact = $this->contactText($overlay, $options);
        if ($contact !== null) {
            $data['Contact instruction'] = $contact;
        }

        $lines = ['Write the final profile bio using only these facts:'];
        foreach ($data as $label => $value) {
            $lines[] = "- {$label}: {$value}";
        }

        return implode("\n", $lines);
    }

    /**
     * Fold quick-action refinements (e.g. ['longer', 'less_generic']) into
     * the explicit override map. Each refinement bumps word/char ranges and
     * appends a phrase to custom_prompt.
     *
     * Additive: passing the same preset twice doubles the effect.
     */
    private function applyRefinements(array $overrides, array $refinements, string $previousBio): array
    {
        if (empty($refinements) && $previousBio === '') {
            return $overrides;
        }

        $stored = config('services.seo_engine.generation', []);
        $base = array_merge(self::DEFAULT_GENERATION, is_array($stored) ? $stored : [], $overrides);

        $minWords = (int) $base['min_words'];
        $maxWords = (int) $base['max_words'];
        $maxChars = (int) $base['max_characters'];
        $prompt   = trim((string) $base['custom_prompt']);
        $addenda  = [];

        foreach ($refinements as $name) {
            $preset = self::REFINEMENT_PRESETS[$name] ?? null;
            if (!$preset) {
                continue;
            }
            $minWords += (int) ($preset['min_words_delta'] ?? 0);
            $maxWords += (int) ($preset['max_words_delta'] ?? 0);
            $maxChars += (int) ($preset['max_chars_delta'] ?? 0);
            if (!empty($preset['prompt_addendum'])) {
                $addenda[] = $preset['prompt_addendum'];
            }
        }

        if ($previousBio !== '') {
            $clean = mb_substr(trim(strip_tags($previousBio)), 0, 600);
            if ($clean !== '') {
                $addenda[] = "Previous draft (do not repeat phrasing): \"{$clean}\"";
            }
        }

        if (!empty($addenda)) {
            $prompt = trim($prompt . "\n" . implode("\n", $addenda));
            $overrides['custom_prompt'] = $prompt;
        }

        // Only set range overrides if a refinement actually moved them
        if ($minWords !== (int) $base['min_words']) {
            $overrides['min_words'] = max(25, min(500, $minWords));
        }
        if ($maxWords !== (int) $base['max_words']) {
            $overrides['max_words'] = max($overrides['min_words'] ?? $minWords, min(700, $maxWords));
        }
        if ($maxChars !== (int) $base['max_characters']) {
            $overrides['max_characters'] = max(200, min(5000, $maxChars));
        }

        return $overrides;
    }

    private function generationOptions(array $overrides = []): array
    {
        $stored = config('services.seo_engine.generation', []);
        $options = array_merge(self::DEFAULT_GENERATION, is_array($stored) ? $stored : [], $overrides);
        $options['tone'] = trim((string) $options['tone']) ?: self::DEFAULT_GENERATION['tone'];
        $options['temperament'] = trim((string) $options['temperament']) ?: self::DEFAULT_GENERATION['temperament'];
        $options['min_words'] = max(25, min(500, (int) $options['min_words']));
        $options['max_words'] = max($options['min_words'], min(700, (int) $options['max_words']));
        $options['max_characters'] = max(200, min(5000, (int) $options['max_characters']));
        $options['max_services'] = max(0, min(20, (int) $options['max_services']));
        $options['include_location'] = (bool) $options['include_location'];
        $options['include_services'] = (bool) $options['include_services'];
        $options['include_contact'] = (bool) $options['include_contact'];
        $options['contact_channel'] = in_array($options['contact_channel'], ['none', 'phone', 'whatsapp', 'both'], true)
            ? $options['contact_channel']
            : self::DEFAULT_GENERATION['contact_channel'];
        $options['custom_prompt'] = trim((string) $options['custom_prompt']);

        $lang = strtolower(trim((string) ($options['language'] ?? 'en')));
        $options['language'] = array_key_exists($lang, self::SUPPORTED_LANGUAGES)
            ? $lang
            : self::DEFAULT_GENERATION['language'];

        return $options;
    }

    private function contactText(array $overlay, array $options): ?string
    {
        if (!$options['include_contact'] || $options['contact_channel'] === 'none') {
            return null;
        }

        $phone = $this->firstString($overlay, ['whatsapp', 'whatsapp_number', 'whatsappnumber', 'phone', 'phone_normalized']);
        if ($phone === '') {
            return 'Mention that visitors can use the contact buttons on the profile. Do not invent a number.';
        }

        return match ($options['contact_channel']) {
            'phone' => "Mention phone contact: {$phone}.",
            'both' => "Mention phone/WhatsApp contact: {$phone}.",
            default => "Mention WhatsApp contact: {$phone}.",
        };
    }

    private function firstString(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (!empty($data[$key]) && !is_array($data[$key])) {
                return trim((string) $data[$key]);
            }
        }

        return '';
    }

    private function maxTokensForOptions(array $options): int
    {
        return max(96, min(1200, (int) ceil(((int) $options['max_words']) * 1.8) + 48));
    }

    /**
     * Strip emoji, decorative symbols, zero-width joiners, smart-quotes that don't render
     * cleanly on WP/MySQL, and other non-prose Unicode. Preserves accented Latin characters,
     * standard punctuation, and currency symbols.
     */
    private function sanitizeOutput(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        // 0) Repair classic mojibake — LLMs sometimes return UTF-8 bytes that
        //    were already decoded once through Latin-1/Windows-1252, producing
        //    "régulière" → "rÃ©guliÃ¨re". MUST run before sanitization so the
        //    regex passes below see real characters.
        $text = $this->demojibake($text);

        // 1) Strip markdown code fences if the model wrapped output (```...```)
        $text = preg_replace('/^```[a-z]*\s*|\s*```$/im', '', $text) ?? $text;

        // 2) Strip emoji & pictographic characters (broad ranges).
        $emojiPattern = '/['
            . '\x{1F300}-\x{1F5FF}'  // misc symbols & pictographs
            . '\x{1F600}-\x{1F64F}'  // emoticons
            . '\x{1F680}-\x{1F6FF}'  // transport & map
            . '\x{1F700}-\x{1F77F}'  // alchemical
            . '\x{1F780}-\x{1F7FF}'  // geometric shapes ext
            . '\x{1F800}-\x{1F8FF}'  // supplemental arrows-C
            . '\x{1F900}-\x{1F9FF}'  // supplemental symbols & pictographs
            . '\x{1FA00}-\x{1FA6F}'  // chess symbols
            . '\x{1FA70}-\x{1FAFF}'  // symbols & pictographs ext-A
            . '\x{2600}-\x{26FF}'    // misc symbols (☀ ☂ ★)
            . '\x{2700}-\x{27BF}'    // dingbats (✂ ✈ ✨)
            . '\x{FE00}-\x{FE0F}'    // variation selectors
            . '\x{1F1E6}-\x{1F1FF}'  // regional indicators (flags)
            . '\x{200D}'              // zero-width joiner
            . '\x{2028}\x{2029}'      // line/paragraph separators
            . ']/u';
        $text = preg_replace($emojiPattern, '', $text) ?? $text;

        // 3) Normalize fancy quotes/dashes that often render as ? on WP.
        $text = strtr($text, [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => ',', "\u{201B}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{201F}" => '"',
            "\u{2013}" => '-', "\u{2014}" => ' - ', "\u{2015}" => '-',
            "\u{2026}" => '...',
            "\u{00A0}" => ' ', "\u{200B}" => '', "\u{FEFF}" => '',
        ]);

        // 4) Collapse runs of whitespace and ensure no leading/trailing whitespace.
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Repair "double-encoded UTF-8" mojibake that some LLMs produce on
     * non-English output. Pattern: `é` (UTF-8 0xC3 0xA9) got interpreted as
     * Latin-1 chars `Ã` + `©` and re-encoded to UTF-8 (4 bytes), so the
     * editor sees `Ã©`.
     *
     * Detection is conservative: we only run the fix when the string
     * contains the characteristic mojibake signatures AND the re-decode
     * yields valid UTF-8 with no replacement chars. If the heuristic
     * doesn't fire, we return the original text untouched so we never
     * mangle already-correct strings.
     */
    private function demojibake(string $text): string
    {
        // Quick reject — no characteristic mojibake glyphs.
        // Common signatures: Ã[©¨ ç¢] (latin accents), â€[™"\'] (smart quotes/dashes).
        if (!preg_match('/Ã[\x{0080}-\x{00FF}]|â€[™"\'\x{0080}-\x{00BF}]/u', $text)) {
            return $text;
        }

        // Attempt the classic fix: re-interpret the UTF-8 byte sequence as if
        // it were Latin-1 (ISO-8859-1). That collapses the double-encoded
        // bytes back to the original UTF-8 bytes of the intended character.
        $attempt = @mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
        if ($attempt === false || $attempt === '') {
            return $text;
        }

        // The result must be valid UTF-8 — otherwise we've made things worse.
        if (!mb_check_encoding($attempt, 'UTF-8')) {
            return $text;
        }

        // Sanity check: the fix should REMOVE the mojibake signatures.
        // If they're still present, the input wasn't pure-mojibake (mixed
        // proper + mojibaked) and our fix would mangle the proper bytes —
        // bail rather than partially corrupting.
        if (preg_match('/Ã[\x{0080}-\x{00FF}]|â€[™"\'\x{0080}-\x{00BF}]/u', $attempt)) {
            return $text;
        }

        return $attempt;
    }

    private function enforceCharacterLimit(string $text, int $limit): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);
        $lastStop = max(mb_strrpos($cut, '.') ?: 0, mb_strrpos($cut, '!') ?: 0, mb_strrpos($cut, '?') ?: 0);

        return trim($lastStop > 120 ? mb_substr($cut, 0, $lastStop + 1) : $cut);
    }


    private function linkContactNumbers(string $html, array $overlay, array $options): string
    {
        if (!$options['include_contact'] || $options['contact_channel'] === 'none') {
            return $html;
        }

        $phone = $this->firstString($overlay, ['whatsapp', 'whatsapp_number', 'whatsappnumber', 'phone', 'phone_normalized']);
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '' || strlen($digits) < 7) {
            return $html;
        }

        $href = in_array($options['contact_channel'], ['phone'], true)
            ? 'tel:+' . $digits
            : 'https://wa.me/' . $digits;

        $escaped = htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $linked = '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $escaped . '</a>';

        return preg_replace('/(?<![\d>])' . preg_quote($escaped, '/') . '(?![\d<])/', $linked, $html, 1) ?? $html;
    }

    private function withCostEstimate(string $provider, array $usage): array
    {
        $input = (int) ($usage['input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);
        $pricing = self::PROVIDER_PRICING[$provider] ?? ['input' => 0.00, 'output' => 0.00];
        $cost = (($input * $pricing['input']) + ($output * $pricing['output'])) / 1_000_000;

        return array_merge($usage, [
            'estimated_cost_usd' => round($cost, 6),
            'estimated_cost_label' => $cost > 0 ? '$' . number_format($cost, 6) : '$0.000000',
            'pricing_basis' => 'Estimated from provider token usage and configured default per-1M-token rates.',
        ]);
    }

    private function textToHtml(string $text): string
    {
        // Split on double newlines → paragraphs; wrap each in <p>
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
