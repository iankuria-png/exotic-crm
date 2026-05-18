<?php

namespace App\Services\Seo\Llm\Adapters;

use App\Services\Seo\Llm\LlmClient;
use App\Services\Seo\Llm\LlmResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiAdapter implements LlmClient
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = (string) config('services.seo_engine.gemini.api_key', '');
        $this->model  = (string) config('services.seo_engine.gemini.model', '');
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function isAvailable(): bool
    {
        return $this->apiKey !== '' && $this->model !== '';
    }

    public function generate(string $system, string $user, array $opts = []): LlmResponse
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('Gemini adapter not configured (missing API key or model).');
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        $payload = [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $system . "\n\n" . $user]],
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => (int) ($opts['max_tokens'] ?? 1024),
            ],
        ];

        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->timeout(30)
            ->post($url, $payload);

        if ($response->failed()) {
            throw new RuntimeException('Gemini API error: ' . $response->status() . ' ' . $response->body());
        }

        $json = $response->json();
        $text = (string) ($json['candidates'][0]['content']['parts'][0]['text'] ?? '');

        if ($text === '') {
            throw new RuntimeException('Gemini API returned empty content.');
        }

        $inputTokens  = (int) ($json['usageMetadata']['promptTokenCount'] ?? 0);
        $outputTokens = (int) ($json['usageMetadata']['candidatesTokenCount'] ?? 0);

        return new LlmResponse(
            text:         $text,
            inputTokens:  $inputTokens,
            outputTokens: $outputTokens,
        );
    }
}
