<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\IntegrationSetting;
use App\Models\Platform;
use App\Services\Seo\Llm\ProviderBalanceService;
use App\Services\Seo\Llm\ProviderWaterfall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * SEO Engine settings — feature flag, platform allowlist, provider order, API keys.
 *
 * Stored as a single JSON blob under IntegrationSetting key 'seo_engine'.
 * Returned API keys are masked for read; only writes provide the real value.
 */
class SeoSettingsController extends Controller
{
    private const KEY = 'seo_engine';

    private const SUPPORTED_PROVIDERS = ['openrouter', 'deepseek', 'gemini', 'claude', 'openai'];

    private const DEFAULT_MODELS = [
        'openrouter' => 'google/gemini-3.8-flash',
        'claude' => 'claude-3-5-sonnet-20241022',
        'openai' => 'gpt-4o-mini',
        'gemini' => 'gemini-2.5-flash',
        'deepseek' => 'deepseek-v4-pro',
    ];

    private const DEFAULT_FALLBACK_MODELS = [
        'openrouter' => ['deepseek/deepseek-v3.2', 'qwen/qwen3-max', 'mistralai/mistral-medium-3.1'],
        'claude' => [],
        'openai' => [],
        'gemini' => [],
        'deepseek' => ['deepseek-v4-flash'],
    ];

    private const OPENROUTER_MODEL_PRESETS = [
        'balanced' => [
            'label' => 'Balanced SEO bios',
            'models' => ['google/gemini-3.8-flash', 'deepseek/deepseek-v3.2', 'qwen/qwen3-max', 'mistralai/mistral-medium-3.1'],
        ],
        'lowest_cost' => [
            'label' => 'Lowest cost',
            'models' => ['qwen/qwen3.8-flash', '~deepseek/deepseek-v4-flash-latest', 'google/gemini-2.5-flash-lite'],
        ],
        'quality_review' => [
            'label' => 'Quality review',
            'models' => ['anthropic/claude-sonnet-4.6', 'google/gemini-3.8-flash', 'openai/gpt-5-mini'],
        ],
        'creative_voice' => [
            'label' => 'Creative voice',
            'models' => ['mistralai/mistral-medium-3-5', 'google/gemini-3.8-flash', 'deepseek/deepseek-v3.2'],
        ],
        'bulk_generation' => [
            'label' => 'Bulk generation',
            'models' => ['deepseek/deepseek-v3.2', 'qwen/qwen3.8-flash', 'google/gemini-2.5-flash-lite'],
        ],
    ];

    private const DEFAULT_GENERATION = [
        'tone' => 'seductive, unique, sexy, witty, flirty, suggestive, human-written profile copy',
        'temperament' => 'confident, playful, raw, and magnetic without sounding scripted',
        'min_words' => 75,
        'max_words' => 115,
        'max_characters' => 900,
        'max_services' => 5,
        'include_location' => true,
        'include_services' => true,
        'include_contact' => true,
        'contact_channel' => 'whatsapp',
        'custom_prompt' => '',
        'language' => 'en',
        'bio_format' => 'auto',
        'creativity' => 0.85,
        'overuse_sensitivity' => 'medium',
        'ignored_overuse_terms' => [],
        'overuse_lookback_days' => 60,
        'previous_bio_reference_min_uniqueness_score' => 70,
    ];

    private const SUPPORTED_LANGUAGES = ['en', 'fr', 'pt', 'sw'];

    /**
     * GET /api/crm/settings/seo-engine
     * Returns current config with API keys masked.
     */
    public function show(Request $request): JsonResponse
    {
        $stored = $this->loadStored();
        $stored['providers'] = $this->maskApiKeys($stored['providers']);

        return response()->json([
            'config' => $stored,
            'available_providers' => self::SUPPORTED_PROVIDERS,
            'env_keys_detected' => $this->detectEnvKeys(),
            'openrouter_model_presets' => self::OPENROUTER_MODEL_PRESETS,
            'available_languages' => collect(\App\Services\Seo\BioGenerationService::SUPPORTED_LANGUAGES)
                ->map(fn ($info, $code) => ['code' => $code, 'label' => $info['label']])
                ->values()
                ->all(),
            'platforms' => Platform::query()
                ->orderBy('name')
                ->get(['id', 'name', 'country'])
                ->map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->name, 'country' => $p->country])
                ->all(),
        ]);
    }

    /**
     * PATCH /api/crm/settings/seo-engine
     * Update the stored config. Sentinel value '__keep__' on api_key preserves
     * the previously stored secret (so the masked UI display doesn't overwrite it).
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'platform_allowlist' => 'array',
            'platform_allowlist.*' => 'integer|min:1',
            'providers_order' => 'array',
            'providers_order.*' => ['string', Rule::in(self::SUPPORTED_PROVIDERS)],
            'providers' => 'array',
            'providers.*.api_key' => 'nullable|string',
            'providers.*.model' => 'nullable|string|max:100',
            'providers.*.fallback_models' => 'nullable|array|max:5',
            'providers.*.fallback_models.*' => 'string|max:100',
            'generation' => 'nullable|array',
            'generation.tone' => 'nullable|string|max:180',
            'generation.temperament' => 'nullable|string|max:180',
            'generation.min_words' => 'nullable|integer|min:25|max:500',
            'generation.max_words' => 'nullable|integer|min:40|max:700',
            'generation.max_characters' => 'nullable|integer|min:200|max:5000',
            'generation.max_services' => 'nullable|integer|min:0|max:20',
            'generation.include_location' => 'nullable|boolean',
            'generation.include_services' => 'nullable|boolean',
            'generation.include_contact' => 'nullable|boolean',
            'generation.contact_channel' => 'nullable|string|in:none,phone,whatsapp,both',
            'generation.custom_prompt' => 'nullable|string|max:2000',
            'generation.language' => ['nullable', 'string', Rule::in(self::SUPPORTED_LANGUAGES)],
            'generation.bio_format' => ['nullable', 'string', Rule::in(array_keys(\App\Services\Seo\BioGenerationService::BIO_FORMATS))],
            'generation.creativity' => 'nullable|numeric|min:0|max:1.4',
            'generation.overuse_sensitivity' => 'nullable|string|in:low,medium,high',
            'generation.ignored_overuse_terms' => 'nullable|array|max:100',
            'generation.ignored_overuse_terms.*' => 'string|max:80',
            'generation.overuse_lookback_days' => 'nullable|integer|min:7|max:365',
            'generation.previous_bio_reference_min_uniqueness_score' => 'nullable|integer|min:0|max:100',
        ]);

        $previous = $this->loadStored();

        // Merge providers, preserving secrets that the UI sent as the masked sentinel.
        $providers = [];
        foreach (self::SUPPORTED_PROVIDERS as $name) {
            $existing = $previous['providers'][$name] ?? ['api_key' => '', 'model' => self::DEFAULT_MODELS[$name]];
            $incoming = $data['providers'][$name] ?? [];

            $apiKey = array_key_exists('api_key', $incoming) ? (string) $incoming['api_key'] : '__keep__';
            if ($apiKey === '__keep__') {
                $apiKey = $existing['api_key'] ?? '';
            }

            $model = isset($incoming['model']) && $incoming['model'] !== ''
                ? (string) $incoming['model']
                : ($existing['model'] ?: self::DEFAULT_MODELS[$name]);
            $model = $this->normalizeProviderModel($name, $model);

            $fallbackModels = array_key_exists('fallback_models', $incoming)
                ? $incoming['fallback_models']
                : ($existing['fallback_models'] ?? self::DEFAULT_FALLBACK_MODELS[$name]);

            $providers[$name] = [
                'api_key' => $apiKey,
                'model' => $model,
                'fallback_models' => $this->normalizeFallbackModels($name, is_array($fallbackModels) ? $fallbackModels : [], $model),
            ];
        }

        $providersOrder = $data['providers_order'] ?? array_keys(array_filter(
            $providers,
            fn ($p) => ($p['api_key'] ?? '') !== ''
        ));

        $payload = [
            'enabled' => (bool) $data['enabled'],
            'platform_allowlist' => array_values(array_unique(array_map('intval', $data['platform_allowlist'] ?? []))),
            'providers_order' => array_values(array_unique($providersOrder)),
            'providers' => $providers,
            'generation' => $this->normalizeGeneration($data['generation'] ?? $previous['generation'] ?? []),
        ];

        $persistedPayload = $payload;
        $persistedPayload['providers'] = $this->encryptProviderKeys($providers);

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => $persistedPayload, 'updated_by' => $request->user()?->id],
        );

        // Bust any cached link catalogs, etc.
        Cache::forget('seo_engine_config');
        $this->applyToRuntimeConfig($payload);

        Log::info('seo.settings.updated', [
            'enabled' => $payload['enabled'],
            'allowlist_count' => count($payload['platform_allowlist']),
            'configured_providers' => array_keys(array_filter(
                $providers,
                fn ($p) => ($p['api_key'] ?? '') !== ''
            )),
            'user_id' => $request->user()?->id,
        ]);

        $resp = $payload;
        $resp['providers'] = $this->maskApiKeys($resp['providers']);

        return response()->json([
            'config' => $resp,
            'message' => 'SEO Engine settings updated.',
        ]);
    }

    /**
     * POST /api/crm/settings/seo-engine/test
     * Run a tiny live call against a single provider with the current stored key.
     */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(self::SUPPORTED_PROVIDERS)],
        ]);

        // Make sure runtime config reflects the latest DB state for this call.
        $this->applyToRuntimeConfig($this->loadStored());

        try {
            $waterfall = ProviderWaterfall::fromConfig($data['provider']);
            $response = $waterfall->generate(
                'You are a health check for the CRM SEO bio generator. Reply with one short sentence that starts with OK.',
                'Confirm this model can return normal visible text.',
                ['max_tokens' => 128, 'temperature' => 0.2],
            );

            return response()->json([
                'success' => true,
                'provider' => $response->provider,
                'text' => mb_substr($response->text, 0, 200),
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
            ]);
        } catch (\Throwable $e) {
            Log::warning('seo.settings.test_failed', [
                'provider' => $data['provider'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'provider' => $data['provider'],
                'error' => $e->getMessage(),
            ], 200);
        }
    }

    /**
     * GET /api/crm/settings/seo-engine/balance?provider=deepseek
     * Returns the remaining credit balance for the requested provider.
     * Cached for 5 minutes to avoid hammering provider APIs on settings refresh.
     */
    public function models(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:80',
            'sort' => ['nullable', 'string', Rule::in([
                'most-popular',
                'pricing-low-to-high',
                'latency-low-to-high',
                'throughput-high-to-low',
                'context-high-to-low',
                'newest',
            ])],
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $query = trim((string) ($data['q'] ?? ''));
        $sort = (string) ($data['sort'] ?? 'most-popular');
        $limit = (int) ($data['limit'] ?? 20);

        $cacheKey = 'seo_openrouter_models_'.md5(json_encode([$query, $sort, $limit]));

        $result = Cache::remember($cacheKey, 900, function () use ($query, $sort, $limit) {
            $response = Http::timeout(20)->get('https://openrouter.ai/api/v1/models', array_filter([
                'output_modalities' => 'text',
                'sort' => $sort,
                'q' => $query !== '' ? $query : null,
            ]));

            if ($response->failed()) {
                return [
                    'success' => false,
                    'message' => 'OpenRouter model catalog returned '.$response->status().'.',
                    'models' => [],
                ];
            }

            $models = collect($response->json('data') ?? [])
                ->filter(fn ($model): bool => is_array($model) && ! str_contains((string) ($model['id'] ?? ''), ':batch'))
                ->take($limit)
                ->map(fn (array $model): array => [
                    'id' => (string) ($model['id'] ?? ''),
                    'name' => (string) ($model['name'] ?? $model['id'] ?? ''),
                    'context_length' => (int) ($model['context_length'] ?? 0),
                    'pricing' => [
                        'prompt' => (string) data_get($model, 'pricing.prompt', '0'),
                        'completion' => (string) data_get($model, 'pricing.completion', '0'),
                    ],
                    'supported_parameters' => array_values((array) ($model['supported_parameters'] ?? [])),
                ])
                ->filter(fn (array $model): bool => $model['id'] !== '')
                ->values()
                ->all();

            return [
                'success' => true,
                'models' => $models,
            ];
        });

        return response()->json($result + [
            'query' => $query,
            'sort' => $sort,
        ]);
    }

    public function balance(Request $request, ProviderBalanceService $balanceService): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(self::SUPPORTED_PROVIDERS)],
        ]);

        $stored = $this->loadStored();
        $apiKey = (string) ($stored['providers'][$data['provider']]['api_key'] ?? '');

        // Fall back to env values if DB has no key
        if ($apiKey === '') {
            $apiKey = (string) match ($data['provider']) {
                'openrouter' => env('OPENROUTER_API_KEY', ''),
                'claude' => env('ANTHROPIC_API_KEY', ''),
                'openai' => env('OPENAI_API_KEY', ''),
                'gemini' => env('GEMINI_API_KEY', ''),
                'deepseek' => env('DEEPSEEK_API_KEY', ''),
                default => '',
            };
        }

        $cacheKey = 'seo_engine_balance_'.$data['provider'].'_'.md5($apiKey);
        $result = Cache::remember($cacheKey, 300, fn () => $balanceService->fetch($data['provider'], $apiKey));

        return response()->json(array_merge(['provider' => $data['provider']], $result));
    }

    // ----------------------------------------------------------------

    /**
     * Load stored config, merged with sensible defaults.
     */
    private function loadStored(): array
    {
        $stored = IntegrationSetting::query()->where('key', self::KEY)->value('value');
        $stored = is_array($stored) ? $stored : [];

        $providers = [];
        foreach (self::SUPPORTED_PROVIDERS as $name) {
            $storedProvider = $stored['providers'][$name] ?? [];
            $storedProvider = is_array($storedProvider) ? $storedProvider : [];

            $providers[$name] = [
                'api_key' => $this->providerApiKey($storedProvider),
                'model' => $this->normalizeProviderModel($name, (string) ($storedProvider['model'] ?? self::DEFAULT_MODELS[$name])),
            ];
            $providers[$name]['fallback_models'] = $this->normalizeFallbackModels(
                $name,
                array_key_exists('fallback_models', $storedProvider)
                    ? (array) $storedProvider['fallback_models']
                    : self::DEFAULT_FALLBACK_MODELS[$name],
                $providers[$name]['model']
            );
        }

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'platform_allowlist' => array_values(array_map('intval', $stored['platform_allowlist'] ?? [])),
            'providers_order' => array_values(array_unique($stored['providers_order'] ?? self::SUPPORTED_PROVIDERS)),
            'providers' => $providers,
            'generation' => $this->normalizeGeneration($stored['generation'] ?? []),
        ];
    }

    private function normalizeGeneration(array $incoming): array
    {
        $generation = array_merge(self::DEFAULT_GENERATION, array_intersect_key($incoming, self::DEFAULT_GENERATION));
        $generation['tone'] = trim((string) $generation['tone']) ?: self::DEFAULT_GENERATION['tone'];
        $generation['temperament'] = trim((string) $generation['temperament']) ?: self::DEFAULT_GENERATION['temperament'];
        $generation['min_words'] = max(25, min(500, (int) $generation['min_words']));
        $generation['max_words'] = max($generation['min_words'], min(700, (int) $generation['max_words']));
        $generation['max_characters'] = max(200, min(5000, (int) $generation['max_characters']));
        $generation = $this->normalizeLengthOverlap($generation);
        $generation['max_services'] = max(0, min(20, (int) $generation['max_services']));
        $generation['include_location'] = (bool) $generation['include_location'];
        $generation['include_services'] = (bool) $generation['include_services'];
        $generation['include_contact'] = (bool) $generation['include_contact'];
        $generation['contact_channel'] = in_array($generation['contact_channel'], ['none', 'phone', 'whatsapp', 'both'], true)
            ? $generation['contact_channel']
            : self::DEFAULT_GENERATION['contact_channel'];
        $generation['custom_prompt'] = trim((string) $generation['custom_prompt']);
        $lang = strtolower(trim((string) ($generation['language'] ?? 'en')));
        $generation['language'] = in_array($lang, self::SUPPORTED_LANGUAGES, true)
            ? $lang
            : self::DEFAULT_GENERATION['language'];
        $format = strtolower(trim((string) ($generation['bio_format'] ?? 'auto')));
        $generation['bio_format'] = array_key_exists($format, \App\Services\Seo\BioGenerationService::BIO_FORMATS)
            ? $format
            : self::DEFAULT_GENERATION['bio_format'];
        $generation['creativity'] = max(0.0, min(1.4, (float) ($generation['creativity'] ?? self::DEFAULT_GENERATION['creativity'])));
        $generation['overuse_sensitivity'] = in_array(($generation['overuse_sensitivity'] ?? 'medium'), ['low', 'medium', 'high'], true)
            ? $generation['overuse_sensitivity']
            : self::DEFAULT_GENERATION['overuse_sensitivity'];
        $generation['ignored_overuse_terms'] = is_array($generation['ignored_overuse_terms'] ?? null)
            ? array_values(array_filter(array_map('strval', $generation['ignored_overuse_terms'])))
            : [];
        $generation['overuse_lookback_days'] = max(7, min(365, (int) ($generation['overuse_lookback_days'] ?? self::DEFAULT_GENERATION['overuse_lookback_days'])));
        $generation['previous_bio_reference_min_uniqueness_score'] = max(0, min(100, (int) ($generation['previous_bio_reference_min_uniqueness_score'] ?? self::DEFAULT_GENERATION['previous_bio_reference_min_uniqueness_score'])));

        return $generation;
    }

    private function normalizeLengthOverlap(array $generation): array
    {
        $maxChars = (int) $generation['max_characters'];
        $estimatedMaxWords = max(25, (int) floor($maxChars / 7.2));
        $estimatedMinWords = max(25, (int) floor($maxChars / 10));

        if ((int) $generation['min_words'] > $estimatedMaxWords) {
            $generation['min_words'] = $estimatedMinWords;
        }

        if ((int) $generation['max_words'] > $estimatedMaxWords) {
            $generation['max_words'] = $estimatedMaxWords;
        }

        $generation['max_words'] = max((int) $generation['min_words'], (int) $generation['max_words']);

        return $generation;
    }

    /**
     * Replace each provider's api_key with a masked placeholder so we never
     * leak the secret over the API.
     */
    private function maskApiKeys(array $providers): array
    {
        foreach ($providers as $name => $p) {
            $key = (string) ($p['api_key'] ?? '');
            if ($key === '') {
                $providers[$name]['api_key'] = '';
                $providers[$name]['has_key'] = false;
            } else {
                $providers[$name]['api_key'] = '__keep__';
                $providers[$name]['has_key'] = true;
                $providers[$name]['key_preview'] = mb_substr($key, 0, 6).'…'.mb_substr($key, -4);
            }
        }

        return $providers;
    }

    private function encryptProviderKeys(array $providers): array
    {
        foreach ($providers as $name => $provider) {
            $key = trim((string) ($provider['api_key'] ?? ''));
            unset($provider['api_key']);

            $provider['api_key_encrypted'] = $key !== ''
                ? Crypt::encryptString($key)
                : '';

            $providers[$name] = $provider;
        }

        return $providers;
    }

    private function providerApiKey(array $provider): string
    {
        $encrypted = (string) ($provider['api_key_encrypted'] ?? '');
        if ($encrypted !== '') {
            try {
                return Crypt::decryptString($encrypted);
            } catch (\Throwable $e) {
                Log::warning('seo.settings.decrypt_key_failed', [
                    'error' => $e->getMessage(),
                ]);

                return '';
            }
        }

        return trim((string) ($provider['api_key'] ?? ''));
    }

    private function normalizeProviderModel(string $provider, string $model): string
    {
        $model = trim($model);

        if ($provider === 'gemini' && in_array($model, ['gemini-1.5-flash', 'gemini-1.5-pro'], true)) {
            return self::DEFAULT_MODELS['gemini'];
        }

        return $model;
    }

    private function normalizeFallbackModels(string $provider, array $models, string $primaryModel): array
    {
        return collect($models)
            ->map(fn ($model) => $this->normalizeProviderModel($provider, (string) $model))
            ->map(fn ($model) => trim($model))
            ->filter(fn ($model) => $model !== '' && $model !== $primaryModel)
            ->unique()
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * Detect whether each provider's API key is already set via .env, so the UI
     * can show "Using .env value" instead of an empty field.
     */
    private function detectEnvKeys(): array
    {
        return [
            'openrouter' => (bool) env('OPENROUTER_API_KEY'),
            'claude' => (bool) env('ANTHROPIC_API_KEY'),
            'openai' => (bool) env('OPENAI_API_KEY'),
            'gemini' => (bool) env('GEMINI_API_KEY'),
            'deepseek' => (bool) env('DEEPSEEK_API_KEY'),
        ];
    }

    /**
     * Push DB-stored settings into the runtime config for the current request.
     * Same logic lives in SeoEngineConfigProvider for normal request lifecycle.
     */
    private function applyToRuntimeConfig(array $stored): void
    {
        config(['services.seo_engine.enabled' => (bool) ($stored['enabled'] ?? false)]);

        if (! empty($stored['platform_allowlist'])) {
            config(['services.seo_engine.platform_allowlist' => $stored['platform_allowlist']]);
        }
        if (! empty($stored['providers_order'])) {
            config(['services.seo_engine.providers' => $stored['providers_order']]);
        }
        config(['services.seo_engine.generation' => $this->normalizeGeneration($stored['generation'] ?? [])]);

        foreach (self::SUPPORTED_PROVIDERS as $name) {
            $key = $stored['providers'][$name]['api_key'] ?? '';
            $model = $stored['providers'][$name]['model'] ?? '';
            $fallbackModels = $stored['providers'][$name]['fallback_models'] ?? [];
            if ($key !== '') {
                config(["services.seo_engine.{$name}.api_key" => $key]);
            }
            if ($model !== '') {
                config(["services.seo_engine.{$name}.model" => $model]);
            }
            config(["services.seo_engine.{$name}.fallback_models" => is_array($fallbackModels) ? $fallbackModels : []]);
        }
    }
}
