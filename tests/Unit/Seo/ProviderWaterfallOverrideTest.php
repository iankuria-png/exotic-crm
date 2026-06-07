<?php

namespace Tests\Unit\Seo;

use App\Services\Seo\Llm\Adapters\ClaudeAdapter;
use App\Services\Seo\Llm\Adapters\DeepSeekAdapter;
use App\Services\Seo\Llm\Adapters\GeminiAdapter;
use App\Services\Seo\Llm\Adapters\OpenAiAdapter;
use App\Services\Seo\Llm\LlmClient;
use App\Services\Seo\Llm\LlmResponse;
use App\Services\Seo\Llm\ProviderWaterfall;
use Tests\TestCase;

class ProviderWaterfallOverrideTest extends TestCase
{
    public function test_from_config_honors_explicit_provider_order(): void
    {
        $this->app->instance(OpenAiAdapter::class, $this->fakeAdapter('openai', shouldFail: true));
        $this->app->instance(ClaudeAdapter::class, $this->fakeAdapter('claude'));

        $waterfall = ProviderWaterfall::fromConfig(null, ['openai', 'claude']);
        $response = $waterfall->generate('system', 'user');

        $this->assertSame('claude', $response->provider);
        $this->assertSame(['openai', 'claude'], array_column($waterfall->lastAttempts(), 'provider'));
    }

    public function test_from_config_falls_back_to_global_provider_order_when_override_is_absent(): void
    {
        config(['services.seo_engine.providers' => ['gemini', 'deepseek']]);

        $this->app->instance(GeminiAdapter::class, $this->fakeAdapter('gemini', shouldFail: true));
        $this->app->instance(DeepSeekAdapter::class, $this->fakeAdapter('deepseek'));

        $waterfall = ProviderWaterfall::fromConfig();
        $response = $waterfall->generate('system', 'user');

        $this->assertSame('deepseek', $response->provider);
        $this->assertSame(['gemini', 'deepseek'], array_column($waterfall->lastAttempts(), 'provider'));
    }

    private function fakeAdapter(string $name, bool $shouldFail = false): LlmClient
    {
        return new class($name, $shouldFail) implements LlmClient {
            public function __construct(
                private readonly string $name,
                private readonly bool $shouldFail,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function generate(string $system, string $user, array $opts = []): LlmResponse
            {
                if ($this->shouldFail) {
                    throw new \RuntimeException("{$this->name} failed");
                }

                return new LlmResponse(
                    text: "{$this->name} response",
                    inputTokens: 10,
                    outputTokens: 5,
                );
            }
        };
    }
}
