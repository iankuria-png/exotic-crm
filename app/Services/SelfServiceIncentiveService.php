<?php

namespace App\Services;

use App\Models\BillingSubscriptionRule;
use Illuminate\Support\Carbon;

class SelfServiceIncentiveService
{
    /**
     * @return array<string, mixed>|null
     */
    public function resolveActiveIncentive(int $platformId, ?string $source = null): ?array
    {
        $rule = BillingSubscriptionRule::query()
            ->where('market_id', $platformId)
            ->first();

        if (!$rule) {
            return null;
        }

        $incentive = data_get($rule->discount_json, 'self_service_incentive');
        if (!is_array($incentive) || !((bool) ($incentive['enabled'] ?? false))) {
            return null;
        }

        $sources = collect($incentive['sources'] ?? ['wallet', 'self_checkout', 'manual_submission'])
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->filter()
            ->values()
            ->all();

        $normalizedSource = $source !== null ? strtolower(trim($source)) : null;
        if ($normalizedSource !== null && !in_array($normalizedSource, $sources, true)) {
            return null;
        }

        $now = now();
        $startsAt = $this->parseDate($incentive['starts_at'] ?? null);
        $expiresAt = $this->parseDate($incentive['expires_at'] ?? null);

        if ($startsAt && $now->lt($startsAt)) {
            return null;
        }

        if ($expiresAt && $now->gt($expiresAt)) {
            return null;
        }

        $percent = isset($incentive['percent']) ? round((float) $incentive['percent'], 2) : null;
        if ($percent === null || $percent <= 0) {
            return null;
        }

        return [
            'enabled' => true,
            'percent' => $percent,
            'label' => trim((string) ($incentive['label'] ?? '')) ?: null,
            'starts_at' => $startsAt?->toIso8601String(),
            'expires_at' => $expiresAt?->toIso8601String(),
            'sources' => $sources,
        ];
    }

    public function resolveForPlatform(int $platformId, string $source): ?float
    {
        return data_get($this->resolveActiveIncentive($platformId, $source), 'percent');
    }

    /**
     * @return array<string, float>|null
     */
    public function applyToAmount(float $amount, ?float $percent): ?array
    {
        $roundedAmount = round($amount, 2);
        $roundedPercent = $percent !== null ? round($percent, 2) : null;

        if ($roundedAmount <= 0 || $roundedPercent === null || $roundedPercent <= 0) {
            return null;
        }

        $discountedAmount = round($roundedAmount * (1 - ($roundedPercent / 100)), 2);

        return [
            'original_amount' => $roundedAmount,
            'amount' => max(0, $discountedAmount),
            'percent' => $roundedPercent,
        ];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
