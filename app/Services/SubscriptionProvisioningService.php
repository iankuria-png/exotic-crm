<?php

namespace App\Services;

use App\Models\BillingRoutingDecision;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\TimelineEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class SubscriptionProvisioningService
{
    public function activateDeal(Deal $deal, array $options = []): Deal
    {
        $deal->loadMissing(['client.platform', 'product', 'platform']);

        $client = $deal->client;
        if (!$client) {
            throw new InvalidArgumentException('Deal has no associated client.');
        }

        $payment = $options['payment'] ?? null;
        if ($payment !== null && !$payment instanceof Payment) {
            throw new InvalidArgumentException('Provisioning payment must be a Payment model.');
        }

        if ((string) $deal->status === 'active') {
            $this->updateLinkedPayment($payment, $deal, $client, $options);

            return $deal->fresh(['client', 'product', 'platform']);
        }

        $durationDays = $this->resolveDurationDaysForDeal(
            $deal,
            isset($options['duration_days']) ? (int) $options['duration_days'] : null
        );
        $paymentMethod = $this->normalizePaymentMethod($options['payment_method'] ?? null);
        $isFreeTrial = (bool) ($options['is_free_trial'] ?? false);
        $approvedBy = $options['free_trial_approved_by'] ?? null;
        $actorId = isset($options['actor_id']) ? (int) $options['actor_id'] : null;

        $platform = $client->platform ?? Platform::findOrFail((int) $client->platform_id);
        $wpPostId = (int) ($client->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            throw new InvalidArgumentException('Client is not linked to a WordPress profile.');
        }

        $wpSync = WpSyncService::forPlatform((int) $client->platform_id);
        $wpSync->activateClient($wpPostId, (string) $deal->plan_type, $durationDays, (int) $deal->id);

        $activatedAt = isset($options['activated_at'])
            ? Carbon::parse($options['activated_at'])
            : now();
        $expiresAt = $activatedAt->copy()->addDays($durationDays);
        $paymentReference = $options['payment_reference']
            ?? $payment?->transaction_reference
            ?? $payment?->reference_number;

        $deal->forceFill([
            'status' => 'active',
            'activated_at' => $activatedAt,
            'expires_at' => $expiresAt,
            'payment_id' => $payment?->id ?? $deal->payment_id,
            'payment_reference' => $paymentReference,
            'is_free_trial' => $isFreeTrial,
            'free_trial_approved_by' => $isFreeTrial ? $approvedBy : null,
        ])->save();

        $this->updateLinkedPayment($payment, $deal, $client, array_merge($options, [
            'activated_at' => $activatedAt,
            'expires_at' => $expiresAt,
        ]));

        $syncService = new ClientSyncService($platform);
        $syncedClient = $syncService->syncOne($wpPostId);
        $deal->setRelation('client', $syncedClient);

        if (($options['emit_payment_received_timeline'] ?? false) && $payment) {
            TimelineEvent::create([
                'platform_id' => (int) $client->platform_id,
                'entity_type' => 'client',
                'entity_id' => (int) $client->id,
                'event_type' => 'payment_received',
                'actor_id' => $actorId,
                'content' => [
                    'payment_id' => (int) $payment->id,
                    'deal_id' => (int) $deal->id,
                    'amount' => (float) $payment->amount,
                    'currency' => $payment->currency ?: ($platform->currency_code ?: 'KES'),
                    'transaction_reference' => $payment->transaction_reference,
                ],
                'created_at' => now(),
            ]);
        }

        if (($options['emit_profile_activated_timeline'] ?? true) === true) {
            TimelineEvent::create([
                'platform_id' => (int) $client->platform_id,
                'entity_type' => 'client',
                'entity_id' => (int) $client->id,
                'event_type' => 'profile_activated',
                'actor_id' => $actorId,
                'content' => [
                    'deal_id' => (int) $deal->id,
                    'plan_type' => (string) $deal->plan_type,
                    'duration_days' => $durationDays,
                    'expires_at' => $expiresAt->toDateTimeString(),
                    'payment_method' => $paymentMethod,
                ],
                'created_at' => now(),
            ]);
        }

        if (($options['emit_deal_activated_timeline'] ?? true) === true) {
            TimelineEvent::create([
                'platform_id' => (int) $client->platform_id,
                'entity_type' => 'deal',
                'entity_id' => (int) $deal->id,
                'event_type' => 'deal_activated',
                'actor_id' => $actorId,
                'content' => [
                    'payment_id' => $payment?->id,
                    'duration_days' => $durationDays,
                    'activated_at' => $activatedAt->toDateTimeString(),
                    'expires_at' => $expiresAt->toDateTimeString(),
                    'payment_method' => $paymentMethod,
                ],
                'created_at' => now(),
            ]);
        }

        return $deal->fresh(['client', 'product', 'platform']);
    }

    public function provisionCompletedPayment(Payment $payment, array $options = []): Deal
    {
        $payment->loadMissing(['client.platform', 'platform', 'product']);

        if ((string) $payment->status !== 'completed') {
            throw new InvalidArgumentException('Payment must be completed to create a subscription.');
        }

        if ($this->isSandboxPayment($payment)) {
            throw new InvalidArgumentException('Sandbox payments cannot provision live subscriptions.');
        }

        $client = $options['client'] ?? $payment->client;
        if ($client !== null && !$client instanceof Client) {
            throw new InvalidArgumentException('Provisioning client must be a Client model.');
        }

        if (!$client && $payment->client_id) {
            $client = Client::find((int) $payment->client_id);
        }

        if (!$client) {
            throw new InvalidArgumentException('Payment must be matched to a client first.');
        }

        $existingDeal = null;
        if ($payment->deal_id) {
            $existingDeal = Deal::find((int) $payment->deal_id);
        }

        if (!$existingDeal) {
            $existingDeal = Deal::query()
                ->where('payment_id', (int) $payment->id)
                ->latest('id')
                ->first();
        }

        if (!$existingDeal) {
            $product = $this->resolveProductForPayment($payment);
            $duration = $this->resolveDurationForPayment($payment, $product);
            $planType = $this->resolvePlanTypeFromProduct($product);

            $existingDeal = Deal::create([
                'platform_id' => (int) $payment->platform_id,
                'client_id' => (int) $client->id,
                'payment_id' => (int) $payment->id,
                'product_id' => $product?->id,
                'plan_type' => $planType,
                'amount' => (float) $payment->amount,
                'currency' => $product?->currency ?: ($payment->currency ?: ($payment->platform?->currency_code ?: 'KES')),
                'duration' => $duration,
                'status' => 'pending',
                'assigned_to' => $options['assigned_to'] ?? $client->assigned_to,
                'payment_reference' => $payment->transaction_reference ?? $payment->reference_number,
            ]);
        }

        return $this->activateDeal($existingDeal, array_merge($options, [
            'payment' => $payment,
            'payment_method' => $options['payment_method'] ?? $this->normalizePaymentMethod(data_get($payment->raw_payload, 'method')),
            'duration_days' => $options['duration_days'] ?? $this->resolveDurationDaysForPayment($payment, $existingDeal),
            'payment_reference' => $options['payment_reference']
                ?? $payment->transaction_reference
                ?? $payment->reference_number,
            'emit_payment_received_timeline' => $options['emit_payment_received_timeline'] ?? true,
            'emit_profile_activated_timeline' => $options['emit_profile_activated_timeline'] ?? false,
            'emit_deal_activated_timeline' => $options['emit_deal_activated_timeline'] ?? true,
        ]));
    }

    private function updateLinkedPayment(?Payment $payment, Deal $deal, Client $client, array $options): void
    {
        if (!$payment) {
            return;
        }

        $payment->forceFill(array_filter([
            'client_id' => (int) $client->id,
            'deal_id' => (int) $deal->id,
            'start_date' => $options['activated_at'] ?? $deal->activated_at,
            'end_date' => $options['expires_at'] ?? $deal->expires_at,
            'completed_at' => $payment->completed_at ?? ($options['activated_at'] ?? now()),
            'match_confidence' => $options['match_confidence'] ?? $payment->match_confidence,
            'confirmed_by' => $options['confirmed_by'] ?? $payment->confirmed_by,
            'confirmed_at' => $options['confirmed_at'] ?? $payment->confirmed_at,
            'reconciliation_confidence' => $options['reconciliation_confidence'] ?? $payment->reconciliation_confidence,
            'reconciliation_state' => $options['reconciliation_state'] ?? $payment->reconciliation_state,
        ], static fn ($value) => $value !== null))->save();
    }

    private function resolveDurationDaysForDeal(Deal $deal, ?int $explicitDurationDays = null): int
    {
        if ($explicitDurationDays !== null && $explicitDurationDays > 0) {
            return $explicitDurationDays;
        }

        $legacyToDurationKey = [
            'weekly' => '1_week',
            'biweekly' => '2_weeks',
            'monthly' => '1_month',
        ];

        $duration = (string) $deal->duration;
        $durationKey = $legacyToDurationKey[$duration] ?? null;

        if ($durationKey && $deal->product_id) {
            $catalogDays = ProductPrice::query()
                ->where('product_id', (int) $deal->product_id)
                ->where('duration_key', $durationKey)
                ->where('is_active', true)
                ->value('duration_days');

            if ($catalogDays && (int) $catalogDays > 0) {
                return (int) $catalogDays;
            }
        }

        return match ($duration) {
            'weekly' => 7,
            'biweekly' => 14,
            'monthly' => 30,
            default => 30,
        };
    }

    private function resolveDurationDaysForPayment(Payment $payment, Deal $deal): int
    {
        if ($deal->duration) {
            return $this->resolveDurationDaysForDeal($deal);
        }

        return match ((string) $payment->duration) {
            'weekly' => 7,
            'biweekly' => 14,
            'monthly' => 30,
            default => 30,
        };
    }

    private function resolveDurationForPayment(Payment $payment, ?Product $product): string
    {
        $duration = (string) $payment->duration;
        if (in_array($duration, ['weekly', 'biweekly', 'monthly'], true)) {
            return $duration;
        }

        if ($product) {
            $amount = (float) $payment->amount;
            if ($product->weekly_price && abs((float) $product->weekly_price - $amount) < 0.01) {
                return 'weekly';
            }
            if ($product->biweekly_price && abs((float) $product->biweekly_price - $amount) < 0.01) {
                return 'biweekly';
            }
            if ($product->monthly_price && abs((float) $product->monthly_price - $amount) < 0.01) {
                return 'monthly';
            }
        }

        return 'monthly';
    }

    private function resolveProductForPayment(Payment $payment): ?Product
    {
        $product = $payment->product_id ? Product::find((int) $payment->product_id) : null;
        if ($product && (
            (int) $product->platform_id === 0
            || (int) $payment->platform_id === 0
            || (int) $product->platform_id === (int) $payment->platform_id
        )) {
            return $product;
        }

        return Product::query()
            ->where('is_active', true)
            ->when(
                !empty($payment->platform_id),
                fn (Builder $builder) => $builder->where(function (Builder $platformScoped) use ($payment) {
                    $platformScoped->where('platform_id', (int) $payment->platform_id)
                        ->orWhereNull('platform_id');
                })
            )
            ->when(
                !empty($payment->currency),
                fn (Builder $builder) => $builder->whereRaw('UPPER(currency) = ?', [strtoupper((string) $payment->currency)])
            )
            ->where(function (Builder $builder) use ($payment): void {
                $builder
                    ->where('monthly_price', (float) $payment->amount)
                    ->orWhere('biweekly_price', (float) $payment->amount)
                    ->orWhere('weekly_price', (float) $payment->amount);
            })
            ->first();
    }

    private function resolvePlanTypeFromProduct(?Product $product): string
    {
        if (!$product) {
            return 'basic';
        }

        $tier = strtolower(trim((string) ($product->tier ?? '')));
        if (in_array($tier, ['basic', 'premium', 'vip', 'vvip'], true)) {
            return $tier;
        }

        $name = strtolower((string) $product->name);
        if (str_contains($name, 'vvip')) {
            return 'vvip';
        }
        if (str_contains($name, 'vip')) {
            return 'vip';
        }
        if (str_contains($name, 'premium')) {
            return 'premium';
        }

        return 'basic';
    }

    private function normalizePaymentMethod(?string $method): string
    {
        $normalized = strtolower(trim((string) $method));

        return match ($normalized) {
            'manual', 'stk', 'link', 'free_trial', 'wallet' => $normalized,
            '', 'provider', 'callback', 'system' => 'provider',
            default => $normalized !== '' ? $normalized : 'provider',
        };
    }

    private function isSandboxPayment(Payment $payment): bool
    {
        return (bool) data_get($payment->payment_data, 'test_mode', false)
            || (
                strtolower(trim((string) $payment->source)) === 'gateway'
                && $this->resolveExecutionEnvironment($payment) === 'sandbox'
            );
    }

    private function resolveExecutionEnvironment(Payment $payment): string
    {
        $decision = $payment->relationLoaded('routingDecisions')
            ? $payment->routingDecisions
                ->sortByDesc(function (BillingRoutingDecision $decision) {
                    return optional($decision->created_at)->getTimestamp() ?? 0;
                })
                ->first()
            : $payment->routingDecisions()
                ->where('immutable_until_terminal_state', true)
                ->latest('id')
                ->first();

        if ($decision instanceof BillingRoutingDecision) {
            return strtolower(trim((string) ($decision->environment ?: 'production')));
        }

        return strtolower(trim((string) ($payment->provider_environment ?: 'production')));
    }
}
