<?php

namespace App\Services\Seo\Llm\Adapters;

use App\Services\Seo\Llm\LlmClient;
use App\Services\Seo\Llm\LlmResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenRouterAdapter implements LlmClient
{
    private string $apiKey;

    private string $model;

    /** @var array<int, string> */
    private array $fallbackModels;

    public function __construct()
    {
        $this->apiKey = (string) config('services.seo_engine.openrouter.api_key', '');
        $this->model = trim((string) config('services.seo_engine.openrouter.model', ''));
        $this->fallbackModels = array_values(array_filter(array_map(
            fn ($model): string => trim((string) $model),
            (array) config('services.seo_engine.openrouter.fallback_models', [])
        )));
    }

    public function name(): string
    {
        return 'openrouter';
    }

    public function isAvailable(): bool
    {
        return $this->apiKey !== '' && $this->model !== '';
    }

    public function generate(string $system, string $user, array $opts = []): LlmResponse
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('OpenRouter adapter not configured (missing API key or model).');
        }

        $failures = [];
        foreach ($this->modelsToTry($opts['model'] ?? null) as $model) {
            try {
                return $this->generateWithModel($model, $system, $user, $opts);
            } catch (\Throwable $e) {
                $failures[$model] = $e->getMessage();
                Log::warning('seo.openrouter_model_failed', [
                    'model' => $model,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $summary = collect($failures)
            ->map(fn (string $error, string $model): string => "{$model}: {$error}")
            ->implode(' | ');

        throw new RuntimeException('OpenRouter API failed for all configured models. '.$summary);
    }

    private function generateWithModel(string $model, string $system, string $user, array $opts = []): LlmResponse
    {
        $maxTokens = max(16, (int) ($opts['max_tokens'] ?? 1024));
        $payload = array_filter([
            'model' => $model,
            'max_tokens' => $maxTokens,
            'temperature' => (float) ($opts['temperature'] ?? 0.85),
            'modalities' => ['text'],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'provider' => $this->providerPreferences(),
        ], fn ($value): bool => $value !== null && $value !== []);

        $response = Http::withHeaders(array_filter([
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type' => 'application/json',
            'HTTP-Referer' => config('services.seo_engine.openrouter.site_url'),
            'X-Title' => config('services.seo_engine.openrouter.app_name'),
            'X-OpenRouter-Metadata' => 'enabled',
        ]))
            ->timeout(45)
            ->post('https://openrouter.ai/api/v1/chat/completions', $payload);

        if ($response->failed()) {
            throw new RuntimeException('OpenRouter API error for '.$model.': '.$response->status().' '.$response->body());
        }

        $json = $response->json();
        $choice = (array) ($json['choices'][0] ?? []);
        $message = (array) ($choice['message'] ?? []);
        $text = $this->extractText($choice);

        if ($text === '') {
            throw new RuntimeException($this->emptyContentMessage($model, $choice, $message, $json));
        }

        return new LlmResponse(
            text: $text,
            inputTokens: (int) ($json['usage']['prompt_tokens'] ?? 0),
            outputTokens: (int) ($json['usage']['completion_tokens'] ?? 0),
        );
    }

    /**
     * @return array<int, string>
     */
    private function modelsToTry(mixed $overrideModel): array
    {
        $override = is_string($overrideModel) ? trim($overrideModel) : '';
        if ($override !== '' && ! str_contains($override, '/')) {
            $override = '';
        }

        return collect([$override, $this->model, ...$this->fallbackModels])
            ->map(fn ($model) => trim((string) $model))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function extractText(array $choice): string
    {
        $message = (array) ($choice['message'] ?? []);
        $content = $message['content'] ?? null;

        if (is_string($content)) {
            return trim($content);
        }

        if (is_array($content)) {
            $parts = collect($content)
                ->map(function ($part): string {
                    if (is_string($part)) {
                        return $part;
                    }

                    if (! is_array($part)) {
                        return '';
                    }

                    foreach (['text', 'content', 'output_text'] as $key) {
                        if (isset($part[$key]) && is_string($part[$key])) {
                            return $part[$key];
                        }
                    }

                    return '';
                })
                ->filter()
                ->implode('');

            return trim($parts);
        }

        return trim((string) ($choice['text'] ?? ''));
    }

    private function emptyContentMessage(string $model, array $choice, array $message, array $json): string
    {
        $details = array_filter([
            'finish_reason' => $choice['finish_reason'] ?? null,
            'native_finish_reason' => $choice['native_finish_reason'] ?? null,
            'provider' => data_get($choice, 'openrouter_metadata.provider_name')
                ?? data_get($json, 'openrouter_metadata.provider_name'),
            'error' => data_get($choice, 'error.message') ?? data_get($json, 'error.message'),
            'refusal' => is_string($message['refusal'] ?? null) ? $message['refusal'] : null,
            'reasoning_tokens' => data_get($json, 'usage.completion_tokens_details.reasoning_tokens'),
        ], fn ($value): bool => $value !== null && $value !== '');

        $suffix = $details === []
            ? ''
            : ' Details: '.collect($details)
                ->map(fn ($value, string $key): string => $key.'='.$this->shortValue($value))
                ->implode(', ');

        return 'OpenRouter API returned empty visible text for '.$model.'.'.$suffix;
    }

    private function shortValue(mixed $value): string
    {
        $text = is_scalar($value)
            ? (string) $value
            : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return mb_substr((string) $text, 0, 180);
    }

    private function providerPreferences(): array
    {
        $preferences = [
            'allow_fallbacks' => (bool) config('services.seo_engine.openrouter.allow_fallbacks', true),
        ];

        $sort = trim((string) config('services.seo_engine.openrouter.provider_sort', ''));
        if (in_array($sort, ['price', 'latency', 'throughput'], true)) {
            $preferences['sort'] = $sort;
        }

        $dataCollection = trim((string) config('services.seo_engine.openrouter.data_collection', 'deny'));
        if (in_array($dataCollection, ['allow', 'deny'], true)) {
            $preferences['data_collection'] = $dataCollection;
        }

        return $preferences;
    }
}
