<?php

namespace App\Services\Ai;

use App\Models\AiInteraction;
use App\Services\Seo\Exceptions\AllProvidersFailedException;
use App\Services\Seo\Llm\ProviderWaterfall;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for AI feature calls (briefings, insights, project intelligence).
 *
 * Wraps the existing SEO provider waterfall (ProviderWaterfall::fromConfig) — there is
 * no net-new waterfall here. Every generate() call logs exactly one ai_interactions row
 * for observability and cost tracking, whether it succeeds or fails.
 *
 * This gateway is read/summarize only. It never performs write actions.
 */
class AiGateway
{
    /**
     * Run a single AI generation and persist one ai_interactions row.
     *
     * @param  string  $feature  Feature slug, e.g. briefing_ceo, insights_chat.
     * @param  array{
     *     user_id?: int|null,
     *     force_provider?: string|null,
     *     generated_sql?: string|null,
     *     result_summary?: string|null,
     *     max_tokens?: int,
     *     model?: string|null
     * }  $opts
     *
     * @throws AllProvidersFailedException when no provider succeeds (after logging the failed row).
     */
    public function generate(string $feature, string $system, string $user, array $opts = []): AiResult
    {
        $userId        = $opts['user_id'] ?? null;
        $forceProvider = $opts['force_provider'] ?? config('ai.providers.force_provider');

        $waterfall = ProviderWaterfall::fromConfig($forceProvider ?: null);

        $startedAt = microtime(true);

        try {
            $response  = $waterfall->generate($system, $user, $opts);
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            $provider = $response->provider;

            $interaction = AiInteraction::create([
                'feature'           => $feature,
                'user_id'           => $userId,
                'prompt'            => $this->loggablePrompt($system, $user),
                'prompt_hash'       => $this->promptHash($system, $user),
                'generated_sql'     => $opts['generated_sql'] ?? null,
                'result_summary'    => $opts['result_summary'] ?? null,
                'provider'          => $provider,
                'status'            => 'success',
                'error_message'     => null,
                'provider_attempts' => $waterfall->lastAttempts(),
                'latency_ms'        => $latencyMs,
                'input_tokens'      => $response->inputTokens,
                'output_tokens'     => $response->outputTokens,
                'est_cost_usd'      => $this->estimateCost($provider, $response->inputTokens, $response->outputTokens),
            ]);

            return new AiResult($response, $interaction);
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            AiInteraction::create([
                'feature'           => $feature,
                'user_id'           => $userId,
                'prompt'            => $this->loggablePrompt($system, $user),
                'prompt_hash'       => $this->promptHash($system, $user),
                'generated_sql'     => $opts['generated_sql'] ?? null,
                'result_summary'    => null,
                'provider'          => null,
                'status'            => 'failed',
                'error_message'     => mb_substr($e->getMessage(), 0, 1000),
                'provider_attempts' => $waterfall->lastAttempts(),
                'latency_ms'        => $latencyMs,
                'input_tokens'      => 0,
                'output_tokens'     => 0,
                'est_cost_usd'      => 0,
            ]);

            Log::warning('ai.generate_failed', [
                'feature' => $feature,
                'error'   => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Estimate USD cost from per-1M-token rates in config('ai.cost_rates').
     * Falls back to the 'default' rate for unknown providers.
     */
    public function estimateCost(?string $provider, int $inputTokens, int $outputTokens): float
    {
        $rates = config('ai.cost_rates', []);
        $rate  = $rates[$provider] ?? $rates['default'] ?? ['input' => 0, 'output' => 0];

        $cost = ($inputTokens / 1_000_000) * (float) $rate['input']
              + ($outputTokens / 1_000_000) * (float) $rate['output'];

        return round($cost, 6);
    }

    private function promptHash(string $system, string $user): string
    {
        return hash('sha256', $system . "\n" . $user);
    }

    /**
     * Respect the configured prompt logging mode:
     *  - full      : store the entire combined prompt
     *  - hash_only : store nothing (hash column still recorded separately)
     *  - truncated : store a bounded prefix (default)
     */
    private function loggablePrompt(string $system, string $user): ?string
    {
        $mode     = (string) config('ai.providers.prompt_logging', 'truncated');
        $combined = trim($system . "\n\n" . $user);

        return match ($mode) {
            'full'      => $combined,
            'hash_only' => null,
            default     => mb_substr($combined, 0, (int) config('ai.providers.prompt_truncate_chars', 2000)),
        };
    }
}
