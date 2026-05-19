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
        $generationOptions = $this->generationOptions(is_array($params['generation_options'] ?? null) ? $params['generation_options'] : []);

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
        $rawText = $this->enforceCharacterLimit($rawText, (int) $generationOptions['max_characters']);

        // Wrap in paragraphs
        $bioHtml = $this->textToHtml($rawText);

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

        return <<<PROMPT
You write short public profile copy for an adult escort directory in {$country}.
Write {$minWords}-{$maxWords} words, no more than {$maxChars} characters.
Tone: {$tone}. Temperament: {$temperament}.
Style rules:
- Write like a concise local classified/profile listing, not a luxury brochure.
- Be specific and factual. Prefer short sentences.
- Do not invent services, locations, contact details, nationality, or claims.
- Avoid generic AI phrases like "sophisticated presence", "natural elegance", "commands attention", "unforgettable experience", "mutual respect", "quality over quantity", "bustling city", "ideal companion", "captivating presence".
- No markdown, no headings, no lists, no emoji.
- Return clean prose only.{$customLine}
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
            'Height' => $snapshot->height ?? '(not specified)',
            'Languages' => !empty($snapshot->languages) ? implode(', ', $snapshot->languages) : 'English',
            'Availability' => $snapshot->availabilityText(),
        ];

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
