<?php

namespace App\Services\Seo\Llm\Adapters;

use App\Services\Seo\Llm\LlmClient;
use App\Services\Seo\Llm\LlmResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DeepSeekAdapter implements LlmClient
{
    private string $apiKey;
    private string $model;
    /** @var array<int, string> */
    private array $fallbackModels;

    public function __construct()
    {
        $this->apiKey = (string) config('services.seo_engine.deepseek.api_key', '');
        $this->model  = trim((string) config('services.seo_engine.deepseek.model', ''));
        $this->fallbackModels = array_values(array_filter(array_map(
            fn ($model): string => trim((string) $model),
            (array) config('services.seo_engine.deepseek.fallback_models', [])
        )));
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

        $failures = [];
        foreach ($this->modelsToTry() as $model) {
            try {
                return $this->generateWithModel($model, $system, $user, $opts);
            } catch (\Throwable $e) {
                $failures[$model] = $e->getMessage();
                Log::warning('seo.deepseek_model_failed', [
                    'model' => $model,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $summary = collect($failures)
            ->map(fn (string $error, string $model): string => "{$model}: {$error}")
            ->implode(' | ');

        throw new RuntimeException('DeepSeek API failed for all configured models. ' . $summary);
    }

    private function generateWithModel(string $model, string $system, string $user, array $opts = []): LlmResponse
    {
        $payload = [
            'model'      => $model,
            'max_tokens' => (int) ($opts['max_tokens'] ?? 1024),
            'temperature' => (float) ($opts['temperature'] ?? 0.85),
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
            throw new RuntimeException('DeepSeek API error for ' . $model . ': ' . $response->status() . ' ' . $response->body());
        }

        $json = $response->json();
        $text = (string) ($json['choices'][0]['message']['content'] ?? '');

        if ($text === '') {
            throw new RuntimeException('DeepSeek API returned empty content for ' . $model . '.');
        }

        return new LlmResponse(
            text:         $text,
            inputTokens:  (int) ($json['usage']['prompt_tokens'] ?? 0),
            outputTokens: (int) ($json['usage']['completion_tokens'] ?? 0),
        );
    }

    /**
     * @return array<int, string>
     */
    private function modelsToTry(): array
    {
        return collect([$this->model, ...$this->fallbackModels])
            ->map(fn ($model) => trim((string) $model))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

}
