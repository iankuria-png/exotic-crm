<?php

namespace Tests\Unit\Seo;

use App\Services\Seo\Llm\Adapters\OpenRouterAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenRouterAdapterTest extends TestCase
{
    public function test_adapter_generates_with_configured_model_chain(): void
    {
        config([
            'services.seo_engine.openrouter.api_key' => 'sk-or-test',
            'services.seo_engine.openrouter.model' => 'google/gemini-3.7-flash',
            'services.seo_engine.openrouter.fallback_models' => ['deepseek/deepseek-v4-flash-0731'],
            'services.seo_engine.openrouter.provider_sort' => 'price',
            'services.seo_engine.openrouter.allow_fallbacks' => true,
            'services.seo_engine.openrouter.data_collection' => 'deny',
            'services.seo_engine.openrouter.site_url' => 'https://crm.test',
            'services.seo_engine.openrouter.app_name' => 'Exotic CRM',
        ]);

        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => '']]]], 200)
                ->push([
                    'choices' => [['message' => ['content' => 'fallback response']]],
                    'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8],
                ], 200),
        ]);

        $response = app(OpenRouterAdapter::class)->generate('system', 'user', ['max_tokens' => 40]);

        $this->assertSame('fallback response', $response->text);
        $this->assertSame(12, $response->inputTokens);
        $this->assertSame(8, $response->outputTokens);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && $request['model'] === 'google/gemini-3.7-flash'
                && data_get($request->data(), 'provider.sort') === 'price'
                && data_get($request->data(), 'provider.data_collection') === 'deny';
        });

        Http::assertSent(function ($request) {
            return $request['model'] === 'deepseek/deepseek-v4-flash-0731';
        });
    }
}
