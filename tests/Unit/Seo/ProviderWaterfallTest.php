<?php

namespace Tests\Unit\Seo;

use App\Services\Seo\Exceptions\AllProvidersFailedException;
use App\Services\Seo\Llm\LlmClient;
use App\Services\Seo\Llm\LlmResponse;
use App\Services\Seo\Llm\ProviderWaterfall;
use Tests\TestCase;

class ProviderWaterfallTest extends TestCase
{
    public function test_failed_provider_error_includes_provider_name_and_api_message(): void
    {
        $waterfall = new ProviderWaterfall([
            new class implements LlmClient {
                public function name(): string
                {
                    return 'gemini';
                }

                public function generate(string $system, string $user, array $opts = []): LlmResponse
                {
                    throw new \RuntimeException('Gemini request failed: {"error":{"message":"API key not valid. Please pass a valid API key.","status":"INVALID_ARGUMENT"}}');
                }
            },
        ]);

        $this->expectException(AllProvidersFailedException::class);
        $this->expectExceptionMessage('gemini: API key not valid. Please pass a valid API key. (INVALID_ARGUMENT)');

        $waterfall->generate('system', 'user');
    }
}
