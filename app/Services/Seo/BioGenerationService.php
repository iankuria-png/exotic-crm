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
 *  6. Return {bio_html, score, breakdown, provider_used}
 */
class BioGenerationService
{
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
     * @param  array  $params  {client_id, wp_post_id, platform_id, profile_snapshot, force_provider}
     * @return array  {bio_html, score, breakdown, provider_used}
     */
    public function generate(array $params): array
    {
        $clientId      = isset($params['client_id']) ? (int) $params['client_id'] : null;
        $wpPostId      = isset($params['wp_post_id']) ? (int) $params['wp_post_id'] : null;
        $platformId    = (int) ($params['platform_id'] ?? 0);
        $overlay       = is_array($params['profile_snapshot'] ?? null) ? $params['profile_snapshot'] : null;
        $forceProvider = isset($params['force_provider']) ? (string) $params['force_provider'] : null;

        // Build normalized snapshot
        if ($clientId !== null || $wpPostId !== null) {
            $snapshot = $this->snapshotBuilder->fromRequest($clientId, $wpPostId, $platformId, $overlay);
            $platformId = $snapshot->platformId; // use resolved platform
        } else {
            // Preview-only: no persisted entity
            $snapshot = $this->snapshotBuilder->fromOverlayOnly($overlay ?? [], $platformId);
        }

        // Generate bio text
        [$rawText, $providerUsed] = $this->generateText($snapshot, $forceProvider);

        // Wrap in paragraphs
        $bioHtml = $this->textToHtml($rawText);

        // Inject internal links
        $catalog = $this->catalogService->forPlatform($snapshot->platformId);
        $bioHtml = $this->injector->inject($bioHtml, $catalog);

        // Score
        $scoreResult = $this->scorer->score($bioHtml, $snapshot);

        Log::info('seo.bio_generated', [
            'client_id'     => $clientId,
            'wp_post_id'    => $wpPostId,
            'platform_id'   => $snapshot->platformId,
            'provider_used' => $providerUsed,
            'score'         => $scoreResult['total'],
        ]);

        return [
            'bio_html'      => $bioHtml,
            'score'         => $scoreResult['total'],
            'breakdown'     => $scoreResult['breakdown'],
            'provider_used' => $providerUsed,
        ];
    }

    // -------------------------------------------------------------------------

    private function generateText(ProfileSnapshot $snapshot, ?string $forceProvider): array
    {
        $waterfall = ProviderWaterfall::fromConfig($forceProvider);

        try {
            $response    = $waterfall->generate(
                $this->buildSystemPrompt($snapshot),
                $this->buildUserPrompt($snapshot),
            );

            return [$response->text, $response->provider];
        } catch (AllProvidersFailedException $e) {
            Log::notice('seo.all_providers_failed', [
                'platform_id' => $snapshot->platformId,
                'reason'      => $e->getMessage(),
            ]);
        }

        return [$this->fallback->generate($snapshot), 'template_fallback'];
    }

    private function buildSystemPrompt(ProfileSnapshot $snapshot): string
    {
        $platform = Platform::find($snapshot->platformId);
        $country  = $platform?->country ?? 'Kenya';

        return <<<PROMPT
You are a professional copywriter for an adult escort directory in {$country}.
Write a short, SEO-optimised profile bio (120–300 words) for the escort described in the user message.
Tone: warm, professional, tasteful. Use third person. Output clean prose only — no markdown, no lists, no headers.
The bio will appear on a public website and must read naturally for both humans and search engines.
PROMPT;
    }

    private function buildUserPrompt(ProfileSnapshot $snapshot): string
    {
        $data = [
            'Name'         => $snapshot->name ?: '(not provided)',
            'Age'          => $snapshot->age !== null ? $snapshot->age . ' years old' : '(not provided)',
            'City'         => $snapshot->city ?: '(not provided)',
            'Neighborhood' => $snapshot->neighborhood ?? '(not specified)',
            'Gender'       => $snapshot->gender ?: 'female',
            'Ethnicity'    => $snapshot->ethnicity ?? '(not specified)',
            'Build'        => $snapshot->build ?? '(not specified)',
            'Height'       => $snapshot->height ?? '(not specified)',
            'Hair color'   => $snapshot->hairColor ?? '(not specified)',
            'Services'     => !empty($snapshot->services) ? implode(', ', $snapshot->services) : '(not specified)',
            'Languages'    => !empty($snapshot->languages) ? implode(', ', $snapshot->languages) : 'English',
            'Availability' => $snapshot->availabilityText(),
        ];

        $lines = ["Write a profile bio for this escort:"];
        foreach ($data as $label => $value) {
            $lines[] = "- {$label}: {$value}";
        }

        return implode("\n", $lines);
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
