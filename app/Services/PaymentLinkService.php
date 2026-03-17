<?php

namespace App\Services;

use App\Models\Platform;

class PaymentLinkService
{
    public function resolveUrl(?Platform $platform, ?string $requestedProvider = null): ?string
    {
        if (!$platform) {
            return null;
        }

        if (is_array($platform->payment_link_providers)) {
            $configuredProvider = trim((string) ($platform->payment_link_providers['active_provider'] ?? ''));
            $activeProvider = trim((string) ($requestedProvider ?: $configuredProvider));
            $providers = $platform->payment_link_providers['providers'] ?? [];

            if ($activeProvider !== '' && is_array($providers) && isset($providers[$activeProvider]) && is_array($providers[$activeProvider])) {
                $provider = $providers[$activeProvider];
                $directUrl = rtrim(trim((string) ($provider['url'] ?? '')), '/');
                if ($directUrl !== '') {
                    return $directUrl;
                }

                $baseUrl = rtrim(trim((string) ($provider['base_url'] ?? '')), '/');
                if ($baseUrl !== '') {
                    $path = trim((string) ($provider['path'] ?? config('services.payment_link.path', '/pay')));
                    if ($path === '') {
                        $path = '/pay';
                    }
                    if (!str_starts_with($path, '/')) {
                        $path = '/' . $path;
                    }

                    return $baseUrl . $path;
                }
            }
        }

        $baseUrl = null;

        if (!empty($platform->wp_api_url)) {
            $baseUrl = preg_replace('#/wp-json/.*$#', '', (string) $platform->wp_api_url);
            $baseUrl = rtrim((string) $baseUrl, '/');
        }

        if (!$baseUrl && !empty($platform->domain)) {
            $domain = trim((string) $platform->domain);
            $baseUrl = str_starts_with($domain, 'http') ? $domain : 'https://' . $domain;
            $baseUrl = rtrim($baseUrl, '/');
        }

        if ($baseUrl === '' || $baseUrl === null) {
            return null;
        }

        $path = config('services.payment_link.path', '/pay');

        return $baseUrl . $path;
    }
}
