<?php

namespace App\Services\Seo\Llm\Adapters;

use App\Services\Seo\Llm\LlmClient;
use App\Services\Seo\Llm\LlmResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ClaudeAdapter implements LlmClient
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = (string) config('services.seo_engine.claude.api_key', '');
        $this->model  = (string) config('services.seo_engine.claude.model', '');
    }

    public function name(): string
    {
        return 'claude';
    }

    public function isAvailable(): bool
    {
        return $this->apiKey !== '' && $this->model !== '';
    }

    public function generate(string $system, string $user, array $opts = []): LlmResponse
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('Claude adapter not configured (missing API key or model).');
        }

        $payload = [
            'model'      => $this->model,
            'max_tokens' => (int) ($opts['max_tokens'] ?? 1024),
            'system'     => $system,
            'messages'   => [
                ['role' => 'user', 'content' => $user],
            ],
        ];

        $response = Http::withHeaders([
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])
            ->timeout(30)
            ->post('https://api.anthropic.com/v1/messages', $payload);

        if ($response->failed()) {
            throw new RuntimeException('Claude API error: ' . $response->status() . ' ' . $response->body());
        }

        $json = $response->json();
        $text = (string) ($json['content'][0]['text'] ?? '');

        if ($text === '') {
            throw new RuntimeException('Claude API returned empty content.');
        }

        return new LlmResponse(
            text:         $text,
            inputTokens:  (int) ($json['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($json['usage']['output_tokens'] ?? 0),
        );
    }
}
