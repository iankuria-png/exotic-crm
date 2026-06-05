<?php

namespace App\Services;

use App\Billing\Support\BillingRoutingDecisionRecorder;
use App\Billing\BillingPermissions;
use App\Billing\Support\MarketBillingMethodPolicy;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Support\CrmAuditAction;
use App\Support\PhoneNormalizer;
use App\Services\Routing\ProviderRoutingDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DealPaymentService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly LegacyStkService $legacyStkService,
        private readonly PaymentLinkService $paymentLinkService,
        private readonly WalletSettingsService $walletSettingsService,
        private readonly MarketBillingMethodPolicy $marketBillingMethodPolicy,
        private readonly SubscriptionLifecycleService $subscriptionLifecycleService,
        private readonly BillingModeService $billingModeService,
        private readonly ProviderRoutingDispatcher $providerRoutingDispatcher,
        private readonly BillingRoutingDecisionRecorder $billingRoutingDecisionRecorder,
        private readonly KopokopoService $kopokopoService
    ) {
    }

    public function marketBillingMethodPolicy(Client|Platform|int|null $scope): array
    {
        $platform = $scope instanceof Client ? $scope->platform : $scope;

        return $this->marketBillingMethodPolicy->forPlatform($platform);
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
        $effectiveCurrencies = $client->platform?->effectiveCurrencies()
            ?? $product->platform?->effectiveCurrencies()
            ?? [strtoupper((string) ($product->currency ?: $client->platform?->currency_code ?: 'KES'))];

        if ($priceRow) {
            $amount = (float) $priceRow->price;
            $resolvedDuration = $this->mapDurationKeyToLegacy($priceRow->duration_key);
            $durationDays = $priceRow->duration_days;
            $currency = strtoupper((string) $priceRow->currency);
        } else {
            if (count($effectiveCurrencies) > 1) {
                throw ValidationException::withMessages([
                    'product_price_id' => 'Select an explicit pricing option for multi-currency deals.',
                ]);
            }

            $resolvedDuration = $duration ?: 'monthly';
            $amount = $this->resolveAmountForDuration($product, $resolvedDuration);
            $durationDays = $this->durationDaysForLegacyDuration($resolvedDuration);
            $currency = $product->currency ?: ($client->platform->currency_code ?? 'KES');
        }

        $lifecycle = $this->subscriptionLifecycleService->resolveForClient(
            $client,
            (int) $client->platform_id
        );

        $deal = Deal::create([
            'platform_id' => $client->platform_id,
            'client_id' => $client->id,
            'lead_id' => $leadId,
            'product_id' => $product->id,
            'product_price_id' => $priceRow?->id,
            'plan_type' => $planType,
            'amount' => $amount,
            'currency' => $currency,
            'duration' => $resolvedDuration,
            'duration_days' => $durationDays,
            'status' => 'pending',
            'assigned_to' => $actorId,
            'subscription_lifecycle' => $lifecycle['subscription_lifecycle'],
            'subscription_lifecycle_source' => $lifecycle['subscription_lifecycle_source'],
            'subscription_lifecycle_reason' => $lifecycle['subscription_lifecycle_reason'],
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

    public function createPendingDealWithCustomPricing(
        Client $client,
        int $baseProductId,
        ?int $baseProductPriceId,
        float $amount,
        int $durationDays,
        int $actorId,
        ?int $leadId
    ): Deal {
        $client->loadMissing('platform');

        $product = $this->resolveScopedProduct($baseProductId, (int) $client->platform_id);
        $basePrice = $baseProductPriceId ? $this->resolveScopedProductPrice($baseProductPriceId, $product) : null;
        $currency = strtoupper((string) (
            $basePrice?->currency
            ?: $product->currency
            ?: $client->platform?->currency_code
            ?: 'KES'
        ));

        $lifecycle = $this->subscriptionLifecycleService->resolveForClient(
            $client,
            (int) $client->platform_id
        );

        $deal = Deal::create([
            'platform_id' => $client->platform_id,
            'client_id' => $client->id,
            'lead_id' => $leadId,
            'product_id' => $product->id,
            'product_price_id' => null,
            'base_product_price_id' => $basePrice?->id,
            'plan_type' => $this->derivePlanTypeFromProduct($product),
            'amount' => round($amount, 2),
            'currency' => $currency,
            'duration' => 'manual',
            'duration_days' => $durationDays,
            'status' => 'pending',
            'assigned_to' => $actorId,
            'subscription_lifecycle' => $lifecycle['subscription_lifecycle'],
            'subscription_lifecycle_source' => $lifecycle['subscription_lifecycle_source'],
            'subscription_lifecycle_reason' => $lifecycle['subscription_lifecycle_reason'],
        ]);

        $baseAmount = $basePrice ? (float) $basePrice->price : null;
        $this->emitDealCreatedTimeline($deal, $client, $actorId, [
            'product_price_id' => null,
            'base_product_price_id' => $basePrice?->id,
            'base_amount' => $baseAmount,
            'custom_pricing' => true,
            'duration_days' => $durationDays,
        ]);

        return $deal;
    }

    public function createSalesPackageAndDeal(
        Client $client,
        int $baseProductId,
        ?int $baseProductPriceId,
        string $packageName,
        float $amount,
        int $durationDays,
        int $actorId,
        ?int $leadId
    ): Deal {
        return DB::transaction(function () use (
            $client,
            $baseProductId,
            $baseProductPriceId,
            $packageName,
            $amount,
            $durationDays,
            $actorId,
            $leadId
        ): Deal {
            $client->loadMissing('platform');
            $baseProduct = $this->resolveScopedProduct($baseProductId, (int) $client->platform_id);
            $basePrice = $baseProductPriceId ? $this->resolveScopedProductPrice($baseProductPriceId, $baseProduct) : null;
            $name = ProductCatalogService::normalizePackageName($packageName);

            if ($name === '') {
                throw ValidationException::withMessages([
                    'new_package_name' => 'Package name is required.',
                ]);
            }

            $duplicate = Product::query()
                ->where('platform_id', (int) $client->platform_id)
                ->whereRaw('UPPER(name) = ?', [$name])
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'new_package_name' => 'A package with this name already exists for this market.',
                ]);
            }

            $currency = strtoupper((string) (
                $basePrice?->currency
                ?: $baseProduct->currency
                ?: $client->platform?->currency_code
                ?: 'KES'
            ));
            $sortOrder = ((int) Product::query()
                ->where('platform_id', (int) $client->platform_id)
                ->max('sort_order')) + 10;

            $product = Product::create([
                'platform_id' => (int) $client->platform_id,
                'name' => $name,
                'display_name' => trim($packageName),
                'slug' => ProductCatalogService::generateUniqueSlugForPlatform((int) $client->platform_id, $name),
                'tier' => $baseProduct->tier ?: ProductCatalogService::normalizePackageTier('', (string) $baseProduct->name),
                'weekly_price' => 0,
                'biweekly_price' => 0,
                'monthly_price' => 0,
                'currency' => $currency,
                'is_active' => true,
                'is_public' => false,
                'is_archived' => false,
                'origin' => 'sales',
                'created_by_user_id' => $actorId,
                'sort_order' => $sortOrder,
            ]);

            $price = ProductPrice::create([
                'product_id' => (int) $product->id,
                'duration_key' => 'custom_' . $durationDays . 'd',
                'duration_label' => $durationDays . ' days',
                'duration_days' => $durationDays,
                'price' => round($amount, 2),
                'currency' => $currency,
                'is_active' => true,
                'sort_order' => 0,
            ]);

            return $this->createPendingDealFromCatalog(
                $client,
                (int) $product->id,
                (int) $price->id,
                null,
                $actorId,
                $leadId
            );
        });
    }

    public function createManualPaymentForDeal(
        Deal $deal,
        Client $client,
        string $paymentReference,
        int $actorId,
        array $overrides = []
    ): Payment
    {
        $reference = trim($paymentReference);
        if ($reference === '') {
            throw new \InvalidArgumentException('Manual payment reference is required.');
        }

        $referenceRoot = $overrides['reference_root'] ?? $this->normalizeReferenceRoot($reference);
        $transactionUuid = trim((string) ($overrides['transaction_uuid'] ?? ''));
        if ($transactionUuid === '') {
            $transactionUuid = 'manual_' . $deal->id . '_' . now()->timestamp;
        }

        $rawPayload = is_array($overrides['raw_payload'] ?? null)
            ? $overrides['raw_payload']
            : [
                'source' => 'deal_manual_payment',
                'deal_id' => (int) $deal->id,
            ];

        return Payment::create([
            'platform_id' => (int) $deal->platform_id,
            'product_id' => $deal->product_id,
            'manual_payment_bundle_id' => $overrides['manual_payment_bundle_id'] ?? null,
            'deal_id' => (int) $deal->id,
            'client_id' => (int) $client->id,
            'phone' => $client->phone_normalized,
            'amount' => (float) ($overrides['amount'] ?? ($deal->amount ?? 0)),
            'currency' => $overrides['currency'] ?? ($deal->currency ?: ($client->platform?->currency_code ?: 'KES')),
            'transaction_uuid' => $transactionUuid,
            'transaction_reference' => $reference,
            'reference_number' => $overrides['reference_number'] ?? $reference,
            'reference_root' => $referenceRoot,
            'reference_sequence' => $overrides['reference_sequence'] ?? null,
            'status' => $overrides['status'] ?? 'completed',
            'duration' => $deal->duration,
            'subscription_lifecycle' => $overrides['subscription_lifecycle'] ?? $deal->subscription_lifecycle,
            'subscription_lifecycle_source' => $overrides['subscription_lifecycle_source'] ?? $deal->subscription_lifecycle_source,
            'subscription_lifecycle_reason' => $overrides['subscription_lifecycle_reason'] ?? $deal->subscription_lifecycle_reason,
            'raw_payload' => $rawPayload,
            'match_confidence' => $overrides['match_confidence'] ?? 'manual',
            'confirmed_by' => $overrides['confirmed_by'] ?? $actorId,
            'confirmed_at' => $overrides['confirmed_at'] ?? now(),
            'reconciliation_state' => $overrides['reconciliation_state'] ?? 'open',
        ]);
    }

    public function initiateSubscriptionPushForDeal(
        Deal $deal,
        Client $client,
        Request $request,
        ?string $targetPhone = null,
        array $paymentOverrides = []
    ): array {
        $client->loadMissing('platform');
        $platform = $client->platform;

        if (!$platform) {
            return [
                'success' => false,
                'message' => 'Client has no market configured for subscription push.',
            ];
        }

        try {
            $context = $this->billingModeService->providerContext(
                $platform,
                'kopokopo',
                requireEnabled: false,
                environmentOverride: null,
                surface: 'subscription_push'
            );
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage() ?: 'KopoKopo is not configured for subscription push in this market.',
            ];
        }

        if (empty($context['chosen_binding_id']) || empty($context['provider_profile_id'])) {
            return [
                'success' => false,
                'message' => 'KopoKopo is not configured for subscription push in this market. Use a payment link for hosted-checkout markets.',
            ];
        }

        $phone = $this->kopokopoService->normalizePhone($targetPhone ?: $client->phone_normalized);
        if (!preg_match('/^\+254\d{9}$/', $phone)) {
            return [
                'success' => false,
                'message' => 'Enter a valid Kenyan phone number for the M-Pesa STK push.',
            ];
        }

        $transactionUuid = (string) Str::uuid();
        $referenceNumber = $this->crmSubscriptionReference($deal, $client, $transactionUuid);
        $environment = strtolower(trim((string) ($context['environment'] ?? 'production'))) ?: 'production';
        $durationDays = (int) ($deal->duration_days ?: $this->resolveDurationDaysFromCatalog($deal));
        $currency = strtoupper((string) ($deal->currency ?: ($platform->currency_code ?: 'KES')));

        $payment = Payment::create([
            'platform_id' => (int) $deal->platform_id,
            'product_id' => $deal->product_id,
            'deal_id' => (int) $deal->id,
            'client_id' => (int) $client->id,
            'phone' => $phone,
            'amount' => (float) ($deal->amount ?? 0),
            'currency' => $currency,
            'transaction_uuid' => $transactionUuid,
            'transaction_reference' => $referenceNumber,
            'reference_number' => $referenceNumber,
            'status' => 'initiated',
            'purpose' => 'subscription',
            'source' => 'crm_activation',
            'provider_key' => 'kopokopo',
            'provider_environment' => $environment,
            'duration' => $deal->duration,
            'subscription_lifecycle' => $paymentOverrides['subscription_lifecycle'] ?? $deal->subscription_lifecycle,
            'subscription_lifecycle_source' => $paymentOverrides['subscription_lifecycle_source'] ?? $deal->subscription_lifecycle_source,
            'subscription_lifecycle_reason' => $paymentOverrides['subscription_lifecycle_reason'] ?? $deal->subscription_lifecycle_reason,
            'raw_payload' => [
                'source' => 'crm_activation',
                'method' => 'subscription_push',
                'deal_id' => (int) $deal->id,
            ],
            'payment_data' => [
                'product_price_id' => $deal->product_price_id,
                'duration_days' => $durationDays,
                'provider' => 'kopokopo',
                'provider_config_key' => 'kopokopo_subscription_push',
                'provider_mode' => 'subscription_push',
                'checkout_channel' => 'crm',
                'billing_surface' => 'subscription_push',
                'test_mode' => $environment === 'sandbox',
                'customer' => [
                    'name' => (string) $client->name,
                    'email' => (string) ($client->email ?? ''),
                    'phone' => $phone,
                ],
            ],
        ]);

        $payment->loadMissing(['client', 'platform', 'product']);
        $dispatchContext = array_merge($context, [
            'provider_key' => 'kopokopo',
        ]);
        $callbackUrl = $this->billingModeService->buildAbsoluteUrl(
            $platform,
            '/api/billing/mpesa/callback',
            [],
            $environment
        );
        $resolvedProvider = [
            'key' => 'kopokopo_subscription_push',
            'config' => [
                'label' => 'KopoKopo',
                'wallet_provider_key' => 'kopokopo',
                'mode' => 'subscription_push',
                'billing_surface' => 'subscription_push',
                'execution_mode' => 'direct',
                'environment' => $environment,
                'chosen_binding_id' => $context['chosen_binding_id'] ?? null,
                'provider_profile_id' => $context['provider_profile_id'] ?? null,
            ],
        ];
        $pricing = [
            'amount' => (float) $payment->amount,
            'currency' => $currency,
            'quoted_amount' => (float) $payment->amount,
            'quoted_currency' => $currency,
        ];

        $this->billingRoutingDecisionRecorder->recordSelfCheckout(
            $payment,
            $dispatchContext,
            $resolvedProvider,
            $pricing,
            $callbackUrl
        );

        try {
            $action = $this->providerRoutingDispatcher->dispatch($payment, $dispatchContext, [
                'phone' => $phone,
                'request' => $request,
            ]);
        } catch (\Throwable $exception) {
            $freshPayment = $payment->fresh() ?? $payment;

            return [
                'success' => false,
                'message' => $freshPayment->failure_reason ?: $exception->getMessage(),
                'payment' => $freshPayment,
            ];
        }

        return [
            'success' => true,
            'message' => $action['message'] ?? 'STK push sent. Subscription will activate after payment confirmation.',
            'payment' => $payment->fresh(['platform', 'product', 'client']),
            'action' => $action,
        ];
    }

    public function initiatePaymentForDeal(
        Deal $deal,
        Client $client,
        string $method,
        Request $request,
        ?string $paymentLinkProvider = null,
        array $paymentLinkSelection = [],
        array $paymentOverrides = [],
        ?string $targetPhone = null
    ): array {
        $client->loadMissing('platform');

        $phonePrefix = (string) ($client->platform?->phone_prefix ?: '254');
        $phone = PhoneNormalizer::normalize($targetPhone ?: $client->phone_normalized, $phonePrefix);
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
            'subscription_lifecycle' => $paymentOverrides['subscription_lifecycle'] ?? $deal->subscription_lifecycle,
            'subscription_lifecycle_source' => $paymentOverrides['subscription_lifecycle_source'] ?? $deal->subscription_lifecycle_source,
            'subscription_lifecycle_reason' => $paymentOverrides['subscription_lifecycle_reason'] ?? $deal->subscription_lifecycle_reason,
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
                'requested_provider' => $paymentLinkSelection['requested_provider'] ?? null,
                'provider_override_requested' => (bool) ($paymentLinkSelection['override_requested'] ?? false),
                'provider_override_applied' => (bool) ($paymentLinkSelection['override_applied'] ?? false),
                'provider_override_denied' => (bool) ($paymentLinkSelection['override_denied'] ?? false),
                'provider_override_actor_role' => $paymentLinkSelection['actor_role'] ?? null,
                'reason' => (string) ($request->input('reason') ?: 'Send payment link from deal flow'),
                'notification_purpose' => 'deal_activation_payment_link',
                'notification_context' => [
                    'deal_id' => $deal->id,
                ],
                'success_message' => 'Payment link sent by SMS. Subscription will activate after payment confirmation.',
                'disabled_message' => 'Payment link prepared (SMS disabled). Subscription will activate after payment confirmation.',
            ]);

            $paymentReady = !empty($sendResult['payment_url']);

            if (!($sendResult['success'] ?? false) && !$paymentReady) {
                $payment->update([
                    'status' => 'failed',
                    'raw_payload' => [
                        'source' => 'deal_payment_initiation',
                        'method' => 'link',
                        'payment_link_provider' => $paymentLinkProvider,
                        'requested_payment_link_provider' => $paymentLinkSelection['requested_provider'] ?? null,
                        'provider_override_requested' => (bool) ($paymentLinkSelection['override_requested'] ?? false),
                        'provider_override_applied' => (bool) ($paymentLinkSelection['override_applied'] ?? false),
                        'provider_override_denied' => (bool) ($paymentLinkSelection['override_denied'] ?? false),
                        'provider_override_actor_role' => $paymentLinkSelection['actor_role'] ?? null,
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
                    'payment_ready' => false,
                    'payment_url' => $sendResult['payment_url'] ?? null,
                    'sms_result' => $sendResult['notification_result'] ?? null,
                    'phone' => $sendResult['phone'] ?? $phone,
                ];
            }

            $payment->update([
                'status' => 'initiated',
                'failure_reason' => null,
                'provider_key' => $sendResult['provider'] ?? $paymentLinkProvider,
                'raw_payload' => [
                    'source' => 'deal_payment_initiation',
                    'method' => 'link',
                    'payment_link_provider' => $paymentLinkProvider,
                    'requested_payment_link_provider' => $paymentLinkSelection['requested_provider'] ?? null,
                    'provider_override_requested' => (bool) ($paymentLinkSelection['override_requested'] ?? false),
                    'provider_override_applied' => (bool) ($paymentLinkSelection['override_applied'] ?? false),
                    'provider_override_denied' => (bool) ($paymentLinkSelection['override_denied'] ?? false),
                    'provider_override_actor_role' => $paymentLinkSelection['actor_role'] ?? null,
                    'resolved_provider' => $sendResult['provider'] ?? $paymentLinkProvider,
                    'payment_url' => $sendResult['payment_url'] ?? null,
                    'sms_status' => data_get($sendResult, 'notification_result.status'),
                    'delivery_error' => ($sendResult['success'] ?? false) ? null : ($sendResult['message'] ?? null),
                ],
            ]);

            return [
                'success' => (bool) ($sendResult['success'] ?? false),
                'message' => $sendResult['message'] ?? 'Payment link sent by SMS. Subscription will activate after payment confirmation.',
                'payment' => $payment->fresh(['platform', 'product', 'client']),
                'payment_ready' => $paymentReady,
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
        ?string $paymentLinkProvider,
        array $paymentLinkSelection = [],
        bool $allowPreparedLinkFallback = false,
        array $paymentOverrides = []
    ): array {
        $beforeState = [
            'deal_status' => $deal->status,
            'client_profile_status' => $client->profile_status,
            'payment_id' => $deal->payment_id,
            'is_free_trial' => (bool) $deal->is_free_trial,
            'payment_method' => 'link',
        ];

        $initiation = $this->initiatePaymentForDeal(
            $deal,
            $client,
            'link',
            $request,
            $paymentLinkProvider,
            $paymentLinkSelection,
            $paymentOverrides,
            trim((string) $request->input('phone')) ?: null
        );
        $paymentReady = (bool) ($initiation['payment_ready'] ?? false);
        if (!($initiation['success'] ?? false) && !($allowPreparedLinkFallback && $paymentReady)) {
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
                'requested_payment_link_provider' => $paymentLinkSelection['requested_provider'] ?? null,
                'payment_link_provider_override_requested' => (bool) ($paymentLinkSelection['override_requested'] ?? false),
                'payment_link_provider_override_applied' => (bool) ($paymentLinkSelection['override_applied'] ?? false),
                'payment_link_provider_override_denied' => (bool) ($paymentLinkSelection['override_denied'] ?? false),
                'payment_link_provider_override_actor_role' => $paymentLinkSelection['actor_role'] ?? null,
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

    public function resolvePaymentLinkProvider(Client $client, ?string $requestedProvider, ?User $actor = null): array
    {
        $client->loadMissing('platform');
        $config = $client->platform
            ? ($this->walletSettingsService->currentPaymentLinkProviders($client->platform) ?? [])
            : [];

        $providers = collect($config['providers'] ?? [])
            ->filter(fn ($provider): bool => is_array($provider) && (bool) ($provider['enabled'] ?? true));

        if ($providers->isEmpty()) {
            throw ValidationException::withMessages([
                'payment_link_provider' => 'No enabled payment-link providers are configured for this market.',
            ]);
        }

        $requestedProvider = trim((string) $requestedProvider);
        $overrideRequested = $requestedProvider !== '';
        $overrideAllowed = $overrideRequested && BillingPermissions::canAccessBillingWorkspace($actor);
        $overrideApplied = false;

        if ($overrideAllowed) {
            if (!$providers->has($requestedProvider)) {
                throw ValidationException::withMessages([
                    'payment_link_provider' => 'Selected payment-link provider is not enabled for this market.',
                ]);
            }

            $overrideApplied = true;

            return [
                'provider' => $requestedProvider,
                'requested_provider' => $requestedProvider,
                'override_requested' => true,
                'override_allowed' => true,
                'override_applied' => true,
                'override_denied' => false,
                'actor_role' => $actor?->role,
            ];
        }

        $activeProvider = trim((string) ($config['active_provider'] ?? ''));
        $resolvedProvider = null;

        if ($activeProvider !== '' && $providers->has($activeProvider)) {
            $resolvedProvider = $activeProvider;
        } else {
            $resolvedProvider = (string) $providers->keys()->first();
        }

        return [
            'provider' => $resolvedProvider,
            'requested_provider' => $overrideRequested ? $requestedProvider : null,
            'override_requested' => $overrideRequested,
            'override_allowed' => $overrideAllowed,
            'override_applied' => $overrideApplied,
            'override_denied' => $overrideRequested && !$overrideApplied,
            'actor_role' => $actor?->role,
        ];
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

    public function durationDaysForLegacyDuration(string $duration): ?int
    {
        return match ($duration) {
            'weekly' => 7,
            'biweekly' => 14,
            'monthly' => 30,
            default => null,
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
                ->where('currency', strtoupper((string) ($deal->currency ?: '')))
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

    private function crmSubscriptionReference(Deal $deal, Client $client, string $transactionUuid): string
    {
        $hash = strtoupper(substr(hash('sha256', implode('|', [
            (int) $deal->platform_id,
            (int) $client->id,
            (int) $deal->id,
            (int) $deal->product_id,
            (string) $deal->duration,
            $transactionUuid,
        ])), 0, 18));

        return 'CRM-SUB-' . $hash;
    }

    private function emitDealCreatedTimeline(Deal $deal, Client $client, int $actorId, array $content = []): void
    {
        TimelineEvent::create([
            'platform_id' => $client->platform_id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'deal_created',
            'actor_id' => $actorId,
            'content' => array_merge([
                'plan_type' => $deal->plan_type,
                'duration' => $deal->duration,
                'amount' => $deal->amount,
                'product_price_id' => $deal->product_price_id,
                'duration_days' => $deal->duration_days,
            ], $content),
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
    }

    public function normalizeReferenceRoot(string $reference): string
    {
        $normalized = strtoupper(trim($reference));
        $normalized = preg_replace('/\s+/', '', $normalized) ?? '';
        $normalized = preg_replace('/-\d+$/', '', $normalized) ?? $normalized;

        return trim($normalized, '-');
    }
}
