<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\TimelineEvent;
use App\Support\CrmAuditAction;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DealPaymentService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly LegacyStkService $legacyStkService,
        private readonly PaymentLinkService $paymentLinkService
    ) {
    }

    public function createPendingDealFromCatalog(
        Client $client,
        int $productId,
        ?int $productPriceId,
        ?string $duration,
        int $actorId,
        ?int $leadId
    ): Deal {
        $client->loadMissing('platform');

        $product = $this->resolveScopedProduct($productId, (int) $client->platform_id);
        $planType = $this->derivePlanTypeFromProduct($product);
        $priceRow = $productPriceId ? $this->resolveScopedProductPrice($productPriceId, $product) : null;

        if ($priceRow) {
            $amount = (float) $priceRow->price;
            $resolvedDuration = $this->mapDurationKeyToLegacy($priceRow->duration_key);
            $durationDays = $priceRow->duration_days;
        } else {
            $resolvedDuration = $duration ?: 'monthly';
            $amount = $this->resolveAmountForDuration($product, $resolvedDuration);
            $durationDays = null;
        }

        $deal = Deal::create([
            'platform_id' => $client->platform_id,
            'client_id' => $client->id,
            'lead_id' => $leadId,
            'product_id' => $product->id,
            'plan_type' => $planType,
            'amount' => $amount,
            'currency' => $product->currency ?: ($client->platform->currency_code ?? 'KES'),
            'duration' => $resolvedDuration,
            'status' => 'pending',
            'assigned_to' => $actorId,
        ]);

        TimelineEvent::create([
            'platform_id' => $client->platform_id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'deal_created',
            'actor_id' => $actorId,
            'content' => [
                'plan_type' => $deal->plan_type,
                'duration' => $deal->duration,
                'amount' => $deal->amount,
                'product_price_id' => $productPriceId,
                'duration_days' => $durationDays,
            ],
            'created_at' => now(),
        ]);

        TimelineEvent::create([
            'platform_id' => $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'deal_created',
            'actor_id' => $actorId,
            'content' => [
                'deal_id' => $deal->id,
                'plan_type' => $deal->plan_type,
                'amount' => $deal->amount,
            ],
            'created_at' => now(),
        ]);

        return $deal;
    }

    public function createManualPaymentForDeal(Deal $deal, Client $client, string $paymentReference, int $actorId): Payment
    {
        $reference = trim($paymentReference);
        if ($reference === '') {
            throw new \InvalidArgumentException('Manual payment reference is required.');
        }

        return Payment::create([
            'platform_id' => (int) $deal->platform_id,
            'product_id' => $deal->product_id,
            'deal_id' => (int) $deal->id,
            'client_id' => (int) $client->id,
            'phone' => $client->phone_normalized,
            'amount' => (float) ($deal->amount ?? 0),
            'currency' => $deal->currency ?: ($client->platform?->currency_code ?: 'KES'),
            'transaction_uuid' => 'manual_' . $deal->id . '_' . now()->timestamp,
            'transaction_reference' => $reference,
            'status' => 'completed',
            'duration' => $deal->duration,
            'raw_payload' => [
                'source' => 'deal_manual_payment',
                'deal_id' => (int) $deal->id,
            ],
            'match_confidence' => 'manual',
            'confirmed_by' => $actorId,
            'confirmed_at' => now(),
        ]);
    }

    public function initiatePaymentForDeal(
        Deal $deal,
        Client $client,
        string $method,
        Request $request,
        ?string $paymentLinkProvider = null
    ): array {
        $client->loadMissing('platform');

        $phonePrefix = (string) ($client->platform?->phone_prefix ?: '254');
        $phone = PhoneNormalizer::normalize($client->phone_normalized, $phonePrefix);
        if (!$phone) {
            return [
                'success' => false,
                'message' => 'Client has no valid phone number for payment initiation.',
            ];
        }

        $payment = Payment::create([
            'platform_id' => (int) $deal->platform_id,
            'product_id' => $deal->product_id,
            'deal_id' => (int) $deal->id,
            'client_id' => (int) $client->id,
            'phone' => $phone,
            'amount' => (float) ($deal->amount ?? 0),
            'currency' => $deal->currency ?: ($client->platform?->currency_code ?: 'KES'),
            'transaction_uuid' => $method . '_' . $deal->id . '_' . now()->timestamp,
            'transaction_reference' => strtoupper($method) . '-' . $deal->id . '-' . now()->format('YmdHis'),
            'status' => 'initiated',
            'duration' => $deal->duration,
            'raw_payload' => [
                'source' => 'deal_payment_initiation',
                'method' => $method,
                'deal_id' => (int) $deal->id,
                'payment_link_provider' => $paymentLinkProvider,
            ],
        ]);

        if ($method === 'stk') {
            $nameParts = preg_split('/\s+/', trim((string) $client->name), 2) ?: [];
            try {
                $result = $this->legacyStkService->initiateWithTelemetry($payment, [
                    'phone' => $phone,
                    'duration' => $payment->duration ?: $deal->duration ?: 'monthly',
                    'first_name' => $nameParts[0] ?? null,
                    'last_name' => $nameParts[1] ?? null,
                    'email' => $client->email,
                ], $request, optional($request->user())->id);
            } catch (\Throwable $exception) {
                $payment->update([
                    'status' => 'failed',
                    'failure_reason' => mb_substr($exception->getMessage(), 0, 190),
                    'provider_key' => 'mpesa_stk',
                    'raw_payload' => [
                        'source' => 'deal_payment_initiation',
                        'method' => 'stk',
                        'error' => $exception->getMessage(),
                    ],
                ]);

                return [
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'payment' => $payment,
                ];
            }

            if ($result['success']) {
                $updates = [
                    'status' => 'initiated',
                    'failure_reason' => null,
                    'provider_key' => 'mpesa_stk',
                    'provider_environment' => $result['provider_environment'] ?? null,
                    'raw_payload' => [
                        'source' => 'deal_payment_initiation',
                        'method' => 'stk',
                        'transport' => $result['transport'] ?? null,
                        'upstream_url' => $result['upstream_url'] ?? null,
                        'provider_response' => $result['provider_response'] ?? null,
                    ],
                ];
                if (!empty($result['provider_reference'])) {
                    $updates['transaction_reference'] = $result['provider_reference'];
                }
                $payment->update($updates);

                return [
                    'success' => true,
                    'message' => 'STK push sent. Subscription will activate after payment confirmation.',
                    'payment' => $payment->fresh(['platform', 'product', 'client']),
                ];
            }

            Log::warning('Deal STK initiation failed', [
                'deal_id' => $deal->id,
                'payment_id' => $payment->id,
                'provider' => $result['provider'] ?? null,
                'transport' => $result['transport'] ?? null,
                'upstream_url' => $result['upstream_url'] ?? null,
                'http_status' => $result['http_status'] ?? null,
                'redirect_location' => $result['redirect_location'] ?? null,
                'response_body' => $result['response_body'] ?? null,
                'provider_response' => $result['provider_response'] ?? null,
            ]);

            $payment->update([
                'status' => 'failed',
                'failure_reason' => mb_substr((string) ($result['message'] ?? 'STK push could not be initiated.'), 0, 190),
                'provider_key' => 'mpesa_stk',
                'provider_environment' => $result['provider_environment'] ?? null,
                'raw_payload' => [
                    'source' => 'deal_payment_initiation',
                    'method' => 'stk',
                    'transport' => $result['transport'] ?? null,
                    'upstream_url' => $result['upstream_url'] ?? null,
                    'http_status' => $result['http_status'] ?? null,
                    'redirect_location' => $result['redirect_location'] ?? null,
                    'response_body' => $result['response_body'] ?? null,
                    'provider_response' => $result['provider_response'] ?? null,
                ],
            ]);

            return [
                'success' => false,
                'message' => $result['message'] ?? 'STK push could not be initiated.',
                'payment' => $payment,
            ];
        }

        if ($method === 'link') {
            $sendResult = $this->paymentLinkService->sendLink($payment, [
                'request' => $request,
                'channel' => 'sms',
                'phone' => $phone,
                'provider' => $paymentLinkProvider,
                'reason' => (string) ($request->input('reason') ?: 'Send payment link from deal flow'),
                'notification_purpose' => 'deal_activation_payment_link',
                'notification_context' => [
                    'deal_id' => $deal->id,
                ],
                'success_message' => 'Payment link sent by SMS. Subscription will activate after payment confirmation.',
                'disabled_message' => 'Payment link prepared (SMS disabled). Subscription will activate after payment confirmation.',
            ]);

            if (!($sendResult['success'] ?? false)) {
                $payment->update([
                    'status' => 'failed',
                    'raw_payload' => [
                        'source' => 'deal_payment_initiation',
                        'method' => 'link',
                        'payment_link_provider' => $paymentLinkProvider,
                        'resolved_provider' => $sendResult['provider'] ?? $paymentLinkProvider,
                        'payment_url' => $sendResult['payment_url'] ?? null,
                        'error' => $sendResult['message'] ?? 'Payment link SMS could not be sent.',
                        'sms_result' => $sendResult['notification_result'] ?? null,
                    ],
                ]);

                return [
                    'success' => false,
                    'message' => $sendResult['message'] ?? 'Payment link SMS could not be sent.',
                    'payment' => $payment,
                    'payment_url' => $sendResult['payment_url'] ?? null,
                    'sms_result' => $sendResult['notification_result'] ?? null,
                    'phone' => $sendResult['phone'] ?? $phone,
                ];
            }

            $payment->update([
                'status' => 'initiated',
                'provider_key' => $sendResult['provider'] ?? $paymentLinkProvider,
                'raw_payload' => [
                    'source' => 'deal_payment_initiation',
                    'method' => 'link',
                    'payment_link_provider' => $paymentLinkProvider,
                    'resolved_provider' => $sendResult['provider'] ?? $paymentLinkProvider,
                    'payment_url' => $sendResult['payment_url'] ?? null,
                    'sms_status' => data_get($sendResult, 'notification_result.status'),
                ],
            ]);

            return [
                'success' => true,
                'message' => $sendResult['message'] ?? 'Payment link sent by SMS. Subscription will activate after payment confirmation.',
                'payment' => $payment->fresh(['platform', 'product', 'client']),
                'payment_url' => $sendResult['payment_url'] ?? null,
                'sms_result' => $sendResult['notification_result'] ?? null,
                'phone' => $sendResult['phone'] ?? $phone,
            ];
        }

        $payment->update([
            'status' => 'failed',
            'raw_payload' => [
                'source' => 'deal_payment_initiation',
                'method' => $method,
                'error' => 'Unsupported payment method',
            ],
        ]);

        return [
            'success' => false,
            'message' => 'Unsupported payment method.',
            'payment' => $payment,
        ];
    }

    public function startLinkPaymentForDeal(
        Deal $deal,
        Client $client,
        Request $request,
        ?string $paymentLinkProvider
    ): array {
        $beforeState = [
            'deal_status' => $deal->status,
            'client_profile_status' => $client->profile_status,
            'payment_id' => $deal->payment_id,
            'is_free_trial' => (bool) $deal->is_free_trial,
            'payment_method' => 'link',
        ];

        $initiation = $this->initiatePaymentForDeal($deal, $client, 'link', $request, $paymentLinkProvider);
        if (!($initiation['success'] ?? false)) {
            throw new \RuntimeException((string) ($initiation['message'] ?? 'Payment initiation failed.'));
        }

        /** @var \App\Models\Payment $payment */
        $payment = $initiation['payment'];

        $deal->update([
            'status' => 'awaiting_payment',
            'payment_id' => $payment->id,
            'payment_reference' => $payment->transaction_reference,
            'is_free_trial' => false,
            'free_trial_approved_by' => null,
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $client->platform_id,
            CrmAuditAction::DEAL_ACTIVATE,
            'deal',
            (int) $deal->id,
            $beforeState,
            [
                'deal_status' => 'awaiting_payment',
                'payment_id' => $deal->payment_id,
                'payment_reference' => $deal->payment_reference,
                'payment_method' => 'link',
                'payment_link_provider' => $paymentLinkProvider,
            ],
            (string) ($request->input('reason') ?: 'Activation initiated pending payment')
        );

        TimelineEvent::create([
            'platform_id' => $client->platform_id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'deal_payment_initiated',
            'actor_id' => optional($request->user())->id,
            'content' => [
                'payment_id' => $payment->id,
                'payment_method' => 'link',
            ],
            'created_at' => now(),
        ]);

        return [
            'message' => $initiation['message'] ?? 'Payment initiated. Subscription will activate when payment succeeds.',
            'deal' => $deal,
            'payment' => $payment->fresh(['platform', 'product', 'client']),
            'payment_url' => $initiation['payment_url'] ?? null,
            'sms_result' => $initiation['sms_result'] ?? null,
            'phone' => $initiation['phone'] ?? null,
        ];
    }

    public function resolvePaymentLinkProvider(Client $client, ?string $requestedProvider): ?string
    {
        $client->loadMissing('platform');
        $config = is_array($client->platform?->payment_link_providers)
            ? $client->platform->payment_link_providers
            : [];

        $providers = collect($config['providers'] ?? [])
            ->filter(fn ($provider): bool => is_array($provider) && (bool) ($provider['enabled'] ?? true));

        if ($providers->isEmpty()) {
            throw ValidationException::withMessages([
                'payment_link_provider' => 'No enabled payment-link providers are configured for this market.',
            ]);
        }

        $requestedProvider = trim((string) $requestedProvider);
        if ($requestedProvider !== '') {
            if (!$providers->has($requestedProvider)) {
                throw ValidationException::withMessages([
                    'payment_link_provider' => 'Selected payment-link provider is not enabled for this market.',
                ]);
            }

            return $requestedProvider;
        }

        $activeProvider = trim((string) ($config['active_provider'] ?? ''));
        if ($activeProvider !== '' && $providers->has($activeProvider)) {
            return $activeProvider;
        }

        return (string) $providers->keys()->first();
    }

    public function resolveScopedProduct(int $productId, int $platformId): Product
    {
        $product = Product::query()->findOrFail($productId);
        if ((int) ($product->platform_id ?? 0) !== $platformId) {
            throw ValidationException::withMessages([
                'product_id' => 'Selected product does not belong to this market.',
            ]);
        }

        return $product;
    }

    public function derivePlanTypeFromProduct(Product $product): string
    {
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

    public function resolveAmountForDuration(Product $product, string $duration): float
    {
        return (float) match ($duration) {
            'weekly' => $product->weekly_price ?? 0,
            'biweekly' => $product->biweekly_price ?? 0,
            'monthly' => $product->monthly_price ?? 0,
            'manual' => 0,
            default => 0,
        };
    }

    public function resolveScopedProductPrice(int $productPriceId, Product $product): ProductPrice
    {
        $priceRow = ProductPrice::query()
            ->where('id', $productPriceId)
            ->where('product_id', (int) $product->id)
            ->where('is_active', true)
            ->first();

        if (!$priceRow) {
            throw ValidationException::withMessages([
                'product_price_id' => 'The selected pricing option is not available for this package.',
            ]);
        }

        return $priceRow;
    }

    public function mapDurationKeyToLegacy(string $durationKey): string
    {
        return match ($durationKey) {
            '1_week' => 'weekly',
            '2_weeks' => 'biweekly',
            '1_month' => 'monthly',
            default => 'manual',
        };
    }

    public function resolveDurationDaysFromCatalog(Deal $deal): int
    {
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
            'manual' => 30,
            default => 30,
        };
    }
}
