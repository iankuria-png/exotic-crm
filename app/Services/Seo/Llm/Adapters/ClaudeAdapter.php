<?php

namespace App\Services\Seo\Llm\Adapters;

use App\Services\Seo\Llm\LlmClient;
use App\Services\Seo\Llm\LlmResponse;
use App\Services\Seo\Llm\LlmTransport;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ClaudeAdapter implements LlmClient
{
    private string $apiKey;

    private string $model;

    public function __construct()
    {
        $this->apiKey = (string) config('services.seo_engine.claude.api_key', '');
        $this->model = (string) config('services.seo_engine.claude.model', '');
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
        if (! $this->isAvailable()) {
            throw new RuntimeException('Claude adapter not configured (missing API key or model).');
        }

        $requestedModel = is_string($opts['model'] ?? null) ? trim((string) $opts['model']) : '';
        $model = $this->isClaudeModel($requestedModel)
            ? $requestedModel
            : $this->model;

        $payload = [
            'model' => $model,
            'max_tokens' => (int) ($opts['max_tokens'] ?? 1024),
            'temperature' => (float) ($opts['temperature'] ?? 0.85),
            'system' => $system,
            'messages' => [
                ['role' => 'user', 'content' => $user],
            ],
        ];

        $response = LlmTransport::post(Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ]), 'https://api.anthropic.com/v1/messages', $payload, $opts, $this->name(), $model, $this->apiKey, 30);

        if ($response->failed()) {
            throw new RuntimeException('Claude API error: '.$response->status().' '.$response->body());
        }

        $json = $response->json();
        $text = (string) ($json['content'][0]['text'] ?? '');

        if ($text === '') {
            throw new RuntimeException('Claude API returned empty content.');
        }

        return new LlmResponse(
            model: $model,
            text: $text,
            inputTokens: (int) ($json['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($json['usage']['output_tokens'] ?? 0),
        );
    }

    private function isClaudeModel(string $model): bool
    {
        return $model !== ''
            && ! str_contains($model, '/')
            && str_starts_with($model, 'claude-');
    }
}
