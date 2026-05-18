<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\IntegrationSetting;
use App\Models\Platform;
use App\Services\Seo\Llm\ProviderWaterfall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

    private const SUPPORTED_PROVIDERS = ['claude', 'openai', 'gemini', 'deepseek'];

    private const DEFAULT_MODELS = [
        'claude'   => 'claude-3-5-sonnet-20241022',
        'openai'   => 'gpt-4o-mini',
        'gemini'   => 'gemini-2.5-flash',
        'deepseek' => 'deepseek-chat',
    ];

    /**
     * GET /api/crm/settings/seo-engine
     * Returns current config with API keys masked.
     */
    public function show(Request $request): JsonResponse
    {
        $stored = $this->loadStored();
        $stored['providers'] = $this->maskApiKeys($stored['providers']);

        return response()->json([
            'config'    => $stored,
            'available_providers' => self::SUPPORTED_PROVIDERS,
            'env_keys_detected'   => $this->detectEnvKeys(),
            'platforms' => Platform::query()
                ->orderBy('name')
                ->get(['id', 'name', 'country'])
                ->map(fn($p) => ['id' => (int) $p->id, 'name' => $p->name, 'country' => $p->country])
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
            'providers.*.model'   => 'nullable|string|max:100',
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

            $providers[$name] = [
                'api_key' => $apiKey,
                'model'   => $this->normalizeProviderModel($name, $model),
            ];
        }

        $providersOrder = $data['providers_order'] ?? array_keys(array_filter(
            $providers,
            fn($p) => ($p['api_key'] ?? '') !== ''
        ));

        $payload = [
            'enabled' => (bool) $data['enabled'],
            'platform_allowlist' => array_values(array_unique(array_map('intval', $data['platform_allowlist'] ?? []))),
            'providers_order' => array_values(array_unique($providersOrder)),
            'providers' => $providers,
        ];

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => $payload, 'updated_by' => $request->user()?->id],
        );

        // Bust any cached link catalogs, etc.
        Cache::forget('seo_engine_config');
        $this->applyToRuntimeConfig($payload);

        Log::info('seo.settings.updated', [
            'enabled' => $payload['enabled'],
            'allowlist_count' => count($payload['platform_allowlist']),
            'configured_providers' => array_keys(array_filter(
                $providers,
                fn($p) => ($p['api_key'] ?? '') !== ''
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
                'You are a test echo. Reply with exactly the word OK.',
                'Say OK.',
                ['max_tokens' => 16],
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
            $providers[$name] = [
                'api_key' => (string) ($stored['providers'][$name]['api_key'] ?? ''),
                'model'   => $this->normalizeProviderModel($name, (string) ($stored['providers'][$name]['model'] ?? self::DEFAULT_MODELS[$name])),
            ];
        }

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'platform_allowlist' => array_values(array_map('intval', $stored['platform_allowlist'] ?? [])),
            'providers_order' => array_values(array_unique($stored['providers_order'] ?? self::SUPPORTED_PROVIDERS)),
            'providers' => $providers,
        ];
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
                $providers[$name]['key_preview'] = mb_substr($key, 0, 6) . '…' . mb_substr($key, -4);
            }
        }
        return $providers;
    }

    private function normalizeProviderModel(string $provider, string $model): string
    {
        $model = trim($model);

        if ($provider === 'gemini' && in_array($model, ['gemini-1.5-flash', 'gemini-1.5-pro'], true)) {
            return self::DEFAULT_MODELS['gemini'];
        }

        return $model;
    }

    /**
     * Detect whether each provider's API key is already set via .env, so the UI
     * can show "Using .env value" instead of an empty field.
     */
    private function detectEnvKeys(): array
    {
        return [
            'claude'   => (bool) env('ANTHROPIC_API_KEY'),
            'openai'   => (bool) env('OPENAI_API_KEY'),
            'gemini'   => (bool) env('GEMINI_API_KEY'),
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

        if (!empty($stored['platform_allowlist'])) {
            config(['services.seo_engine.platform_allowlist' => $stored['platform_allowlist']]);
        }
        if (!empty($stored['providers_order'])) {
            config(['services.seo_engine.providers' => $stored['providers_order']]);
        }
        foreach (self::SUPPORTED_PROVIDERS as $name) {
            $key = $stored['providers'][$name]['api_key'] ?? '';
            $model = $stored['providers'][$name]['model'] ?? '';
            if ($key !== '') {
                config(["services.seo_engine.{$name}.api_key" => $key]);
            }
            if ($model !== '') {
                config(["services.seo_engine.{$name}.model" => $model]);
            }
        }
    }
}
