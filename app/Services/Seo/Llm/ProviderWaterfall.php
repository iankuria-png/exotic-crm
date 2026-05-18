<?php

namespace App\Services\Seo\Llm;

use App\Services\Seo\Exceptions\AllProvidersFailedException;
use Illuminate\Support\Facades\Log;

class ProviderWaterfall
{
    /** @param  LlmClient[]  $adapters */
    public function __construct(private readonly array $adapters) {}

    public function generate(string $system, string $user, array $opts = []): LlmResponse
    {
        foreach ($this->adapters as $adapter) {
            try {
                $resp           = $adapter->generate($system, $user, $opts);
                $resp->provider = $adapter->name();
                return $resp;
            } catch (\Throwable $e) {
                Log::warning('seo.provider_failed', [
                    'provider' => $adapter->name(),
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        throw new AllProvidersFailedException();
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

            /** @var \App\Services\Seo\Llm\Adapters\ClaudeAdapter $instance */
            $instance = app($adapterMap[$name]);

            // Skip adapters that aren't configured — avoids unnecessary failures in dev
            if (method_exists($instance, 'isAvailable') && !$instance->isAvailable()) {
                continue;
            }

            $adapters[] = $instance;
        }

        return new self($adapters);
    }
}
