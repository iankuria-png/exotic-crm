<?php

namespace App\Services\Seo\Llm;

use App\Services\Seo\Exceptions\AllProvidersFailedException;
use Illuminate\Support\Facades\Log;

class ProviderWaterfall
{
    /**
     * Per-provider attempt log from the most recent generate() call.
     * Each entry: ['provider' => string, 'status' => 'success'|'failed', 'error' => ?string].
     * Recorded for observability (e.g. AiGateway); SEO callers can ignore it.
     *
     * @var array<int, array{provider: string, status: string, error: ?string}>
     */
    private array $lastAttempts = [];

    /** @param  LlmClient[]  $adapters */
    public function __construct(private readonly array $adapters) {}

    /**
     * @return array<int, array{provider: string, status: string, error: ?string}>
     */
    public function lastAttempts(): array
    {
        return $this->lastAttempts;
    }

    public function generate(string $system, string $user, array $opts = []): LlmResponse
    {
        $failures = [];
        $this->lastAttempts = [];

        foreach ($this->adapters as $adapter) {
            $provider = $adapter->name();

            try {
                $resp           = $adapter->generate($system, $user, $opts);
                $resp->provider = $provider;
                $this->lastAttempts[] = ['provider' => $provider, 'status' => 'success', 'error' => null];
                return $resp;
            } catch (\Throwable $e) {
                $message = $this->summarizeProviderError($e->getMessage());
                $failures[$provider] = $message;
                $this->lastAttempts[] = ['provider' => $provider, 'status' => 'failed', 'error' => $message];

                Log::warning('seo.provider_failed', [
                    'provider' => $provider,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        if ($failures !== []) {
            $summary = collect($failures)
                ->map(fn (string $error, string $provider) => "{$provider}: {$error}")
                ->implode(' | ');

            throw new AllProvidersFailedException("All LLM providers failed. {$summary}");
        }

        throw new AllProvidersFailedException('No configured LLM providers were available. Add a provider API key and model, or rely on template fallback during bio generation.');
    }

    /**
     * Build a waterfall from the SEO providers config, filtering out adapters
     * that have no API key configured (so local dev works without any key).
     *
     * @param  string|null  $forceProvider  If set, only attempt this provider.
     */
    public static function fromConfig(?string $forceProvider = null): self
    {
        $adapterMap = [
            'claude'   => \App\Services\Seo\Llm\Adapters\ClaudeAdapter::class,
            'openai'   => \App\Services\Seo\Llm\Adapters\OpenAiAdapter::class,
            'gemini'   => \App\Services\Seo\Llm\Adapters\GeminiAdapter::class,
            'deepseek' => \App\Services\Seo\Llm\Adapters\DeepSeekAdapter::class,
        ];

        $configuredOrder = config('services.seo_engine.providers', ['claude', 'openai', 'gemini', 'deepseek']);

        if ($forceProvider !== null && isset($adapterMap[$forceProvider])) {
            $configuredOrder = [$forceProvider];
        }

        $adapters = [];
        foreach ($configuredOrder as $name) {
            $name = trim((string) $name);
            if (!isset($adapterMap[$name])) {
                continue;
            }

            /** @var \App\Services\Seo\Llm\LlmClient $instance */
            $instance = app($adapterMap[$name]);

            // Skip adapters that aren't configured — avoids unnecessary failures in dev
            if (method_exists($instance, 'isAvailable') && !$instance->isAvailable()) {
                continue;
            }

            $adapters[] = $instance;
        }

        return new self($adapters);
    }

    private function summarizeProviderError(string $message): string
    {
        $message = trim($message);

        if ($message === '') {
            return 'Unknown provider error.';
        }

        $decoded = null;
        if (preg_match('/\{.*\}\s*$/s', $message, $matches)) {
            $decoded = json_decode($matches[0], true);
        }

        $apiMessage = is_array($decoded) ? data_get($decoded, 'error.message') : null;
        if (is_string($apiMessage) && trim($apiMessage) !== '') {
            $status = data_get($decoded, 'error.status');
            return trim($apiMessage) . ($status ? " ({$status})" : '');
        }

        return mb_substr($message, 0, 500);
    }
}
