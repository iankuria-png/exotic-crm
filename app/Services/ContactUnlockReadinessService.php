<?php

namespace App\Services;

use App\Billing\Support\BillingSurface;
use App\Models\Client;
use App\Models\Platform;
use App\Support\ClientLifecycleState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class ContactUnlockReadinessService
{
    public function __construct(
        private readonly ContactUnlockPricingService $pricingService,
        private readonly BillingModeService $billingModeService
    ) {
    }

    public function check(?int $platformId = null): array
    {
        $platforms = Platform::query()
            ->when($platformId, fn ($query) => $query->whereKey($platformId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $markets = $platforms
            ->map(fn (Platform $platform) => $this->checkPlatform($platform))
            ->values()
            ->all();

        return [
            'checked_at' => now()->toIso8601String(),
            'summary' => [
                'markets_checked' => count($markets),
                'ready' => collect($markets)->where('status', 'ready')->count(),
                'warning' => collect($markets)->where('status', 'warning')->count(),
                'blocked' => collect($markets)->where('status', 'blocked')->count(),
            ],
            'markets' => $markets,
        ];
    }

    private function checkPlatform(Platform $platform): array
    {
        $checks = [];
        $checks[] = $this->booleanCheck(
            'crm_feature_enabled',
            'CRM availability',
            $this->pricingService->enabledForPlatform($platform),
            'Contact unlock is enabled for this market in CRM.',
            'Contact unlock is not enabled for this market in CRM.'
        );

        $rules = $this->pricingService->activeRules($platform);
        $checks[] = $this->booleanCheck(
            'pricing_rules',
            'Pricing rules',
            $rules->isNotEmpty(),
            sprintf('%d active pricing rule(s) configured.', $rules->count()),
            'No active pricing rule is configured for this market.'
        );

        $providerChecks = $this->providerChecks($platform);
        $checks = array_merge($checks, $providerChecks);

        $sample = $this->sampleRestrictedClient($platform);
        $checks[] = $this->sampleCheck($sample);
        $checks[] = $this->wordpressProxyCheck($platform, $sample);

        return [
            'platform_id' => (int) $platform->id,
            'name' => (string) $platform->name,
            'domain' => (string) $platform->domain,
            'currency_code' => (string) ($platform->currency_code ?: ''),
            'status' => $this->overallStatus($checks),
            'sample_profile' => $sample ? [
                'client_id' => (int) $sample->id,
                'name' => (string) $sample->name,
                'wp_post_id' => (int) $sample->wp_post_id,
                'lifecycle_state' => (string) $sample->lifecycle_state,
                'url' => (string) ($sample->wp_profile_permalink ?: $sample->wp_profile_url),
            ] : null,
            'checks' => $checks,
        ];
    }

    private function providerChecks(Platform $platform): array
    {
        return collect($this->pricingService->providerOptions($platform))
            ->map(function (array $provider) use ($platform): array {
                $key = (string) ($provider['key'] ?? '');

                try {
                    $context = $this->billingModeService->providerContext(
                        $platform,
                        $key,
                        true,
                        $this->pricingService->checkoutEnvironment(),
                        BillingSurface::ContactUnlock->value
                    );

                    $environment = (string) ($context['environment'] ?? 'default');
                    $source = (string) ($context['provider_resolved_from'] ?? 'runtime config');

                    return [
                        'key' => 'provider_' . $key,
                        'label' => 'Provider ' . $this->providerLabel($key),
                        'status' => 'pass',
                        'message' => sprintf('%s is ready for %s checkout via %s.', $this->providerLabel($key), $environment, $source),
                    ];
                } catch (Throwable $exception) {
                    return [
                        'key' => 'provider_' . $key,
                        'label' => 'Provider ' . $this->providerLabel($key),
                        'status' => 'fail',
                        'message' => $exception->getMessage(),
                    ];
                }
            })
            ->values()
            ->all();
    }

    private function sampleRestrictedClient(Platform $platform): ?Client
    {
        return Client::query()
            ->where('platform_id', (int) $platform->id)
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '>', 0)
            ->whereIn('lifecycle_state', [ClientLifecycleState::EXPIRED, ClientLifecycleState::ARCHIVED])
            ->latest('last_synced_at')
            ->latest('id')
            ->first();
    }

    private function sampleCheck(?Client $sample): array
    {
        return [
            'key' => 'sample_profile',
            'label' => 'Inactive profile sample',
            'status' => $sample ? 'pass' : 'warn',
            'message' => $sample
                ? sprintf('Testing with WP post #%d.', (int) $sample->wp_post_id)
                : 'No expired or archived CRM client with a WP post id was found, so the public modal endpoint cannot be fully probed.',
        ];
    }

    private function wordpressProxyCheck(Platform $platform, ?Client $sample): array
    {
        if (! $sample) {
            return [
                'key' => 'wp_contact_unlock_proxy',
                'label' => 'WordPress reverse proxy',
                'status' => 'warn',
                'message' => 'Skipped because there is no inactive profile sample.',
            ];
        }

        $endpoint = $this->contactUnlockConfigUrl($platform, (int) $sample->wp_post_id);
        if ($endpoint === '') {
            return [
                'key' => 'wp_contact_unlock_proxy',
                'label' => 'WordPress reverse proxy',
                'status' => 'fail',
                'message' => 'Market has no WordPress API URL or domain to build the contact unlock endpoint.',
            ];
        }

        $started = microtime(true);

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->get($endpoint);
        } catch (ConnectionException $exception) {
            return [
                'key' => 'wp_contact_unlock_proxy',
                'label' => 'WordPress reverse proxy',
                'status' => 'fail',
                'message' => 'Could not reach WordPress: ' . $exception->getMessage(),
                'endpoint' => $endpoint,
            ];
        }

        $latencyMs = (int) round((microtime(true) - $started) * 1000);
        $json = $response->json();
        $code = is_array($json) ? (string) ($json['code'] ?? '') : '';
        $message = is_array($json) ? (string) ($json['message'] ?? '') : '';

        if ($response->status() === 503 && $code === 'not_configured') {
            return [
                'key' => 'wp_contact_unlock_proxy',
                'label' => 'WordPress reverse proxy',
                'status' => 'fail',
                'message' => $message !== ''
                    ? $message
                    : 'WordPress says contact unlock is not configured for WP -> CRM calls.',
                'http_status' => $response->status(),
                'latency_ms' => $latencyMs,
                'endpoint' => $endpoint,
                'hint' => 'Check EXOTIC_CRM_BASE_URL, EXOTIC_CRM_SYNC_SHARED_KEY, and the WP platform id option on this market site.',
            ];
        }

        if ($response->failed()) {
            return [
                'key' => 'wp_contact_unlock_proxy',
                'label' => 'WordPress reverse proxy',
                'status' => 'fail',
                'message' => $message !== ''
                    ? $message
                    : sprintf('WordPress returned HTTP %d.', $response->status()),
                'http_status' => $response->status(),
                'latency_ms' => $latencyMs,
                'endpoint' => $endpoint,
            ];
        }

        $enabled = is_array($json) && array_key_exists('enabled', $json) ? (bool) $json['enabled'] : null;
        $disabledReason = is_array($json) ? (string) ($json['disabled_reason'] ?? '') : '';

        return [
            'key' => 'wp_contact_unlock_proxy',
            'label' => 'WordPress reverse proxy',
            'status' => $enabled === true ? 'pass' : 'warn',
            'message' => $enabled === true
                ? 'WordPress reached CRM and returned enabled checkout config.'
                : ($disabledReason !== ''
                    ? 'WordPress reached CRM, but checkout is disabled: ' . $disabledReason . '.'
                    : 'WordPress reached CRM, but checkout did not return enabled=true.'),
            'http_status' => $response->status(),
            'latency_ms' => $latencyMs,
            'endpoint' => $endpoint,
        ];
    }

    private function contactUnlockConfigUrl(Platform $platform, int $wpPostId): string
    {
        $base = trim((string) $platform->wp_api_url);
        if ($base !== '') {
            $root = preg_replace('#/wp-json/exotic-crm-sync/v1/?$#', '', rtrim($base, '/'));
        } else {
            $domain = trim((string) $platform->domain);
            if ($domain === '') {
                return '';
            }

            $root = str_starts_with($domain, 'http://') || str_starts_with($domain, 'https://')
                ? rtrim($domain, '/')
                : 'https://' . rtrim($domain, '/');
        }

        return $root . '/wp-json/exotic-crm-sync/v1/contact-unlock/config?post_id=' . $wpPostId;
    }

    private function booleanCheck(string $key, string $label, bool $pass, string $passMessage, string $failMessage): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $pass ? 'pass' : 'fail',
            'message' => $pass ? $passMessage : $failMessage,
        ];
    }

    private function overallStatus(array $checks): string
    {
        $statuses = collect($checks)->pluck('status');
        if ($statuses->contains('fail')) {
            return 'blocked';
        }

        if ($statuses->contains('warn')) {
            return 'warning';
        }

        return 'ready';
    }

    private function providerLabel(string $provider): string
    {
        return match (strtolower($provider)) {
            'pawapay' => 'pawaPay',
            'kopokopo' => 'KopoKopo',
            default => $provider,
        };
    }
}
