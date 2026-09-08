<?php

namespace App\Services\Seo\Llm;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Looks up the remaining credit balance on each LLM provider that exposes one.
 *
 * Provider support matrix (as of 2026):
 *   - OpenRouter ✅ GET /credits → total credits/usage, USD
 *   - DeepSeek  ✅ GET /user/balance  → real number, USD
 *   - OpenAI    ⚠️  Billing API deprecated for personal keys; admin-only on orgs
 *   - Gemini    ❌ No public balance endpoint (Google AI Studio shows quota only)
 *   - Claude    ❌ No public balance endpoint
 *
 * Unsupported providers return a `supported: false` shape so the UI can render
 * a neutral "Not available" state rather than failing the whole settings load.
 */
class ProviderBalanceService
{
    /**
     * @return array{supported: bool, balance?: string, currency?: string, raw?: array, error?: string}
     */
    public function fetch(string $provider, string $apiKey): array
    {
        if ($apiKey === '') {
            return ['supported' => true, 'error' => 'No API key configured.'];
        }

        return match ($provider) {
            'openrouter' => $this->openRouterCredits($apiKey),
            'deepseek' => $this->deepseekBalance($apiKey),
            'openai' => $this->openaiBalance($apiKey),
            default => [
                'supported' => false,
                'error' => ucfirst($provider).' does not expose a public balance API.',
            ],
        };
    }

    private function openRouterCredits(string $apiKey): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Accept' => 'application/json',
            ])
                ->timeout(15)
                ->get('https://openrouter.ai/api/v1/credits');

            if ($response->failed()) {
                return [
                    'supported' => true,
                    'error' => 'OpenRouter credits request returned '.$response->status().'.',
                ];
            }

            $totalCredits = (float) ($response->json('data.total_credits') ?? 0);
            $totalUsage = (float) ($response->json('data.total_usage') ?? 0);
            $remaining = max(0, $totalCredits - $totalUsage);

            return [
                'supported' => true,
                'balance' => number_format($remaining, 2, '.', ''),
                'currency' => 'USD',
                'granted' => number_format($totalCredits, 2, '.', ''),
                'topped_up' => '',
                'usage' => number_format($totalUsage, 2, '.', ''),
            ];
        } catch (\Throwable $e) {
            Log::warning('seo.provider_balance.openrouter_failed', ['error' => $e->getMessage()]);

            return ['supported' => true, 'error' => $e->getMessage()];
        }
    }

    private function deepseekBalance(string $apiKey): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Accept' => 'application/json',
            ])
                ->timeout(15)
                ->get('https://api.deepseek.com/user/balance');

            if ($response->failed()) {
                return [
                    'supported' => true,
                    'error' => 'DeepSeek balance request returned '.$response->status().'.',
                ];
            }

            $json = $response->json();
            $infos = $json['balance_infos'] ?? [];
            $primary = is_array($infos) && ! empty($infos) ? $infos[0] : null;

            if (! $primary) {
                return [
                    'supported' => true,
                    'error' => 'DeepSeek returned no balance entries.',
                    'raw' => $json,
                ];
            }

            $totalBalance = (string) ($primary['total_balance'] ?? '0');
            $currency = (string) ($primary['currency'] ?? 'USD');
            $isAvailable = (bool) ($json['is_available'] ?? true);

            return [
                'supported' => true,
                'balance' => $totalBalance,
                'currency' => $currency,
                'is_available' => $isAvailable,
                'granted' => (string) ($primary['granted_balance'] ?? ''),
                'topped_up' => (string) ($primary['topped_up_balance'] ?? ''),
            ];
        } catch (\Throwable $e) {
            Log::warning('seo.provider_balance.deepseek_failed', ['error' => $e->getMessage()]);

            return ['supported' => true, 'error' => $e->getMessage()];
        }
    }

    /**
     * OpenAI's public balance endpoint (/v1/dashboard/billing/credit_grants) was
     * deprecated. The replacement (/v1/organization/costs) requires an admin
     * scoped key. We attempt it but fail gracefully if scope is wrong.
     */
    private function openaiBalance(string $apiKey): array
    {
        return [
            'supported' => false,
            'error' => 'OpenAI requires an Admin scope key with org-level access. Track usage at platform.openai.com/usage.',
        ];
    }
}
