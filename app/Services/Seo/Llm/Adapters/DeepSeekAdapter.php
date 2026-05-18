<?php

namespace App\Services\Seo\Llm\Adapters;

use App\Services\Seo\Llm\LlmClient;
use App\Services\Seo\Llm\LlmResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DeepSeekAdapter implements LlmClient
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = (string) config('services.seo_engine.deepseek.api_key', '');
        $this->model  = (string) config('services.seo_engine.deepseek.model', '');
    }

    public function name(): string
    {
        return 'deepseek';
    }

    public function isAvailable(): bool
    {
        return $this->apiKey !== '' && $this->model !== '';
    }

    public function generate(string $system, string $user, array $opts = []): LlmResponse
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('DeepSeek adapter not configured (missing API key or model).');
        }

        $payload = [
            'model'      => $this->model,
            'max_tokens' => (int) ($opts['max_tokens'] ?? 1024),
            'messages'   => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
        ])
            ->timeout(30)
            ->post('https://api.deepseek.com/chat/completions', $payload);

        if ($response->failed()) {
            throw new RuntimeException('DeepSeek API error: ' . $response->status() . ' ' . $response->body());
        }

        $json = $response->json();
        $text = (string) ($json['choices'][0]['message']['content'] ?? '');

        if ($text === '') {
            throw new RuntimeException('DeepSeek API returned empty content.');
        }

        return new LlmResponse(
            text:         $text,
            inputTokens:  (int) ($json['usage']['prompt_tokens'] ?? 0),
            outputTokens: (int) ($json['usage']['completion_tokens'] ?? 0),
        );
    }
}
