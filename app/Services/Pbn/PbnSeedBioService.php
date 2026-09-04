<?php

namespace App\Services\Pbn;

use App\Models\Client;
use App\Services\AutoOptimize\AutoOptimizeConfig;
use App\Services\Seo\BioGenerationService;
use App\Services\Seo\LanguageDetector;
use Illuminate\Support\Str;

/**
 * Gives a seeded profile its own bio, so a PBN does not carry a byte-identical
 * copy of the source advertiser's text.
 *
 * This deliberately owns no generation logic. BioGenerationService already
 * builds a profile snapshot, runs the provider waterfall, handles per-market
 * language, scores the result and reports cost — and Auto Optimize already
 * proved which guards that output needs before it is safe to publish. Seeding
 * reuses both rather than running a second, weaker pipeline beside them:
 *
 *  - the English-fallback block, so a template in the wrong language never
 *    lands on a non-English market;
 *  - the empty-output reject;
 *  - a duplicate check against the source bio, which for seeding is the whole
 *    point rather than a nicety.
 *
 * Failure degrades rather than stalls: a provider outage falls back to the
 * source text or holds the item, per the batch policy.
 */
class PbnSeedBioService
{
    public const RESULT_REWRITTEN = 'rewritten';
    public const RESULT_TEMPLATE = 'template';
    public const RESULT_FALLBACK = 'fallback';
    public const RESULT_SKIPPED = 'skipped';

    public function __construct(
        private readonly BioGenerationService $bioGenerator,
        private readonly LanguageDetector $languageDetector
    ) {
    }

    /**
     * @param  array<string, mixed>  $seedPolicy  The item's resolved applied_policy.
     * @return array{text: string, result: string, provider: ?string, cost: float, note: ?string}
     */
    public function rewrite(string $sourceBio, Client $client, array $seedPolicy): array
    {
        $sourceBio = trim($sourceBio);

        if (($seedPolicy['bio_mode'] ?? 'verbatim') !== 'rewrite' || $sourceBio === '') {
            return $this->outcome($sourceBio, self::RESULT_SKIPPED, null, 0.0, null);
        }

        $config = AutoOptimizeConfig::effectiveForPlatform((int) $client->platform_id);
        $generation = is_array($config['actions']['generation'] ?? null) ? $config['actions']['generation'] : [];
        $reliability = is_array($config['reliability'] ?? null) ? $config['reliability'] : [];
        $language = $this->resolveLanguage($sourceBio, $generation, $reliability);

        try {
            $generated = $this->bioGenerator->generate([
                'client_id' => (int) $client->id,
                'wp_post_id' => (int) $client->wp_post_id,
                'platform_id' => (int) $client->platform_id,
                'generation_options' => array_merge($generation, ['language' => $language]),
                'previous_bio' => $sourceBio,
            ]);
        } catch (\Throwable $exception) {
            return $this->failure($sourceBio, $seedPolicy, Str::limit($exception->getMessage(), 200, ''));
        }

        $cost = (float) data_get($generated, 'usage.estimated_cost_usd', 0.0);
        $bioHtml = (string) ($generated['bio_html'] ?? '');

        if (trim(strip_tags($bioHtml)) === '') {
            return $this->failure($sourceBio, $seedPolicy, 'Bio generation returned empty text.', $cost);
        }

        // Every provider failed and BioGenerationService substituted its
        // deterministic template. Whether that is publishable is a policy
        // question, so it is answered by the batch rather than assumed.
        if ((bool) ($generated['fallback_used'] ?? false)) {
            return $this->handleTemplateFallback($bioHtml, $sourceBio, $seedPolicy, $language, $cost);
        }

        if ($this->normalise($bioHtml) === $this->normalise($sourceBio)) {
            return $this->failure($sourceBio, $seedPolicy, 'Generation returned the source bio unchanged.', $cost);
        }

        return $this->outcome(
            $bioHtml,
            self::RESULT_REWRITTEN,
            (string) ($generated['provider_used'] ?? null) ?: null,
            $cost,
            null
        );
    }

    /**
     * The template fallback is free, instant and — unlike copying the source —
     * genuinely different text, which is why it is worth offering as the
     * preferred degradation for seeding.
     *
     * Two limits shape when it may be used, and neither is negotiable by
     * config:
     *
     *  - The templates in Services/Seo/Templates are English-only, so a
     *    non-English market falls through to the next option rather than
     *    publishing English. This is the same block Auto Optimize applies.
     *  - TemplateFallbackEngine keys its variant on the source wp_post_id, so
     *    one advertiser seeded to several PBNs receives the SAME template text
     *    on each. It removes the duplicate against the source, not against the
     *    network.
     *
     * @param  array<string, mixed>  $seedPolicy
     * @return array{text: string, result: string, provider: ?string, cost: float, note: ?string}
     */
    private function handleTemplateFallback(string $bioHtml, string $sourceBio, array $seedPolicy, string $language, float $cost): array
    {
        if (($seedPolicy['bio_on_failure'] ?? 'template') !== 'template') {
            return $this->failure($sourceBio, $seedPolicy, 'Every AI provider failed and the batch does not accept the template fallback.', $cost);
        }

        if ($language !== 'en') {
            return $this->failure(
                $sourceBio,
                $seedPolicy,
                'Every AI provider failed and the template fallback is English-only for a ' . $language . ' market.',
                $cost
            );
        }

        return $this->outcome($bioHtml, self::RESULT_TEMPLATE, 'template_fallback', $cost, 'All AI providers failed; the deterministic template was used.');
    }

    /**
     * Honour the market's existing language when the detector is confident,
     * exactly as AutoOptimizeBuilder does — a seeded profile that switches
     * language mid-market is more obviously synthetic than a duplicate bio.
     *
     * @param  array<string, mixed>  $generation
     * @param  array<string, mixed>  $reliability
     */
    private function resolveLanguage(string $sourceBio, array $generation, array $reliability): string
    {
        $language = (string) ($generation['language'] ?? 'en');

        if (!(bool) ($generation['respect_existing_language'] ?? true)) {
            return $language;
        }

        $detected = $this->languageDetector->detect(strip_tags($sourceBio));
        $threshold = (float) ($reliability['language_confidence'] ?? 0.70);

        return ($detected['confidence'] ?? 0) >= $threshold
            ? (string) ($detected['language'] ?? $language)
            : $language;
    }

    private function normalise(string $html): string
    {
        return Str::lower(trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? ''));
    }

    /**
     * @param  array<string, mixed>  $seedPolicy
     * @return array{text: string, result: string, provider: ?string, cost: float, note: ?string}
     */
    private function failure(string $sourceBio, array $seedPolicy, string $note, float $cost = 0.0): array
    {
        if (($seedPolicy['bio_on_failure'] ?? 'template') === 'attention') {
            throw new \RuntimeException('Bio rewrite failed and the batch policy holds the item: ' . $note);
        }

        return $this->outcome($sourceBio, self::RESULT_FALLBACK, null, $cost, $note);
    }

    /**
     * @return array{text: string, result: string, provider: ?string, cost: float, note: ?string}
     */
    private function outcome(string $text, string $result, ?string $provider, float $cost, ?string $note): array
    {
        return ['text' => $text, 'result' => $result, 'provider' => $provider, 'cost' => $cost, 'note' => $note];
    }
}
