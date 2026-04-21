<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use Illuminate\Validation\ValidationException;

class SubscriptionLifecycleService
{
    public const LIFECYCLE_NEW = 'new';
    public const LIFECYCLE_RENEWAL = 'renewal';

    public const SOURCE_PREDICTED = 'predicted';
    public const SOURCE_OPERATOR_OVERRIDE = 'operator_override';

    /**
     * @return array{subscription_lifecycle:string,subscription_lifecycle_source:string,subscription_lifecycle_reason:?string,predicted_subscription_lifecycle:string,operator_overridden:bool}
     */
    public function resolveForClient(
        Client $client,
        ?int $platformId,
        ?string $selectedLifecycle = null,
        ?string $selectedReason = null,
        array $context = []
    ): array {
        $predictedLifecycle = $this->predictForClient($client, $platformId, $context);

        return $this->finalizeSelection(
            $predictedLifecycle,
            $selectedLifecycle,
            $selectedReason
        );
    }

    /**
     * @return array{subscription_lifecycle:string,subscription_lifecycle_source:string,subscription_lifecycle_reason:?string,predicted_subscription_lifecycle:string,operator_overridden:bool}
     */
    public function resolveForDeal(
        Deal $deal,
        ?string $selectedLifecycle = null,
        ?string $selectedReason = null,
        array $context = []
    ): array {
        $deal->loadMissing('client');
        $client = $deal->client;

        if (!$client) {
            throw ValidationException::withMessages([
                'subscription_lifecycle' => 'A client is required to classify the subscription lifecycle.',
            ]);
        }

        return $this->resolveForClient(
            $client,
            (int) ($deal->platform_id ?: $client->platform_id),
            $selectedLifecycle,
            $selectedReason,
            array_merge($context, [
                'exclude_deal_id' => (int) $deal->id,
            ])
        );
    }

    /**
     * @return array{subscription_lifecycle:string,subscription_lifecycle_source:string,subscription_lifecycle_reason:?string,predicted_subscription_lifecycle:string,operator_overridden:bool}
     */
    public function resolveForPayment(
        Payment $payment,
        ?string $selectedLifecycle = null,
        ?string $selectedReason = null,
        array $context = []
    ): array {
        $payment->loadMissing('client');
        $client = $payment->client;

        if (!$client) {
            throw ValidationException::withMessages([
                'subscription_lifecycle' => 'A matched client is required to classify the subscription lifecycle.',
            ]);
        }

        return $this->resolveForClient(
            $client,
            (int) ($payment->platform_id ?: $client->platform_id),
            $selectedLifecycle,
            $selectedReason,
            array_merge($context, [
                'exclude_payment_id' => (int) $payment->id,
            ])
        );
    }

    /**
     * @param  array{subscription_lifecycle:string,subscription_lifecycle_source:string,subscription_lifecycle_reason:?string}  $resolved
     * @return array<string, string|null>
     */
    public function toPersistenceAttributes(array $resolved): array
    {
        return [
            'subscription_lifecycle' => $resolved['subscription_lifecycle'],
            'subscription_lifecycle_source' => $resolved['subscription_lifecycle_source'],
            'subscription_lifecycle_reason' => $resolved['subscription_lifecycle_reason'],
        ];
    }

    /**
     * @return array{subscription_lifecycle:string,subscription_lifecycle_source:string,subscription_lifecycle_reason:?string,predicted_subscription_lifecycle:string,operator_overridden:bool}
     */
    private function finalizeSelection(
        string $predictedLifecycle,
        ?string $selectedLifecycle,
        ?string $selectedReason
    ): array {
        $normalizedSelected = $this->normalizeLifecycle($selectedLifecycle);
        $normalizedReason = $this->normalizeReason($selectedReason);

        if ($normalizedSelected === null || $normalizedSelected === $predictedLifecycle) {
            return [
                'subscription_lifecycle' => $predictedLifecycle,
                'subscription_lifecycle_source' => self::SOURCE_PREDICTED,
                'subscription_lifecycle_reason' => null,
                'predicted_subscription_lifecycle' => $predictedLifecycle,
                'operator_overridden' => false,
            ];
        }

        if ($normalizedReason === null) {
            throw ValidationException::withMessages([
                'subscription_lifecycle_reason' => 'Give a short reason when overriding the lifecycle classification.',
            ]);
        }

        return [
            'subscription_lifecycle' => $normalizedSelected,
            'subscription_lifecycle_source' => self::SOURCE_OPERATOR_OVERRIDE,
            'subscription_lifecycle_reason' => $normalizedReason,
            'predicted_subscription_lifecycle' => $predictedLifecycle,
            'operator_overridden' => true,
        ];
    }

    private function predictForClient(Client $client, ?int $platformId, array $context = []): string
    {
        $forcedLifecycle = $this->normalizeLifecycle($context['force_lifecycle'] ?? null);
        if ($forcedLifecycle !== null) {
            return $forcedLifecycle;
        }

        $resolvedPlatformId = (int) ($platformId ?: $client->platform_id);
        if ($resolvedPlatformId <= 0) {
            return self::LIFECYCLE_NEW;
        }

        if ($this->hasTrackedSubscriptionHistory($client, $resolvedPlatformId, $context)) {
            return self::LIFECYCLE_RENEWAL;
        }

        if ($this->hasLegacySubscriptionHistory($client, $context)) {
            return self::LIFECYCLE_RENEWAL;
        }

        return self::LIFECYCLE_NEW;
    }

    private function hasTrackedSubscriptionHistory(Client $client, int $platformId, array $context = []): bool
    {
        $excludeDealId = (int) ($context['exclude_deal_id'] ?? 0);
        $excludePaymentId = (int) ($context['exclude_payment_id'] ?? 0);

        $dealHistoryExists = Deal::query()
            ->where('client_id', (int) $client->id)
            ->where('platform_id', $platformId)
            ->when(
                $excludeDealId > 0,
                fn ($query) => $query->whereKeyNot($excludeDealId)
            )
            ->where(function ($query) {
                $query->whereNotNull('activated_at')
                    ->orWhereNotNull('expires_at')
                    ->orWhereIn('status', ['active', 'expired', 'renewed', 'cancelled']);
            })
            ->exists();

        if ($dealHistoryExists) {
            return true;
        }

        return Payment::query()
            ->where('client_id', (int) $client->id)
            ->where('platform_id', $platformId)
            ->when(
                $excludePaymentId > 0,
                fn ($query) => $query->whereKeyNot($excludePaymentId)
            )
            ->where(function ($query) {
                $query->whereNotNull('start_date')
                    ->orWhereNotNull('end_date');
            })
            ->exists();
    }

    private function hasLegacySubscriptionHistory(Client $client, array $context = []): bool
    {
        if (!empty($context['ignore_legacy_signals'])) {
            return false;
        }

        return !empty($client->escort_expire)
            || !empty($client->premium_expire)
            || !empty($client->featured_expire)
            || (bool) $client->premium
            || (bool) $client->featured
            || strtolower(trim((string) $client->profile_status)) === 'private';
    }

    private function normalizeLifecycle(?string $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, [self::LIFECYCLE_NEW, self::LIFECYCLE_RENEWAL], true)
            ? $normalized
            : null;
    }

    private function normalizeReason(?string $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
};
