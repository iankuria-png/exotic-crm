<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Deal;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Template;
use App\Models\TimelineEvent;
use App\Models\Platform;
use App\Services\AuditService;
use App\Services\WpSyncService;
use App\Services\ClientSyncService;
use App\Services\DealPaymentService;
use App\Services\MarketAuthorizationService;
use App\Services\NotificationService;
use App\Services\SubscriptionDeactivationService;
use App\Services\ManualPaymentBundleService;
use App\Services\SubscriptionLifecycleService;
use App\Services\SubscriptionProvisioningService;
use App\Services\WalletSettingsService;
use App\Support\DeactivationRequest;
use App\Support\CrmAuditAction;
use App\Support\DealDeactivationReason;
use App\Support\LinkedPaymentAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DealController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly AuditService $auditService,
        private readonly \App\Services\RenewalService $renewalService,
        private readonly DealPaymentService $dealPaymentService,
        private readonly NotificationService $notificationService,
        private readonly SubscriptionDeactivationService $subscriptionDeactivationService,
        private readonly ManualPaymentBundleService $manualPaymentBundleService,
        private readonly SubscriptionProvisioningService $subscriptionProvisioningService,
        private readonly SubscriptionLifecycleService $subscriptionLifecycleService,
        private readonly WalletSettingsService $walletSettingsService
    ) {
    }

    public function index(Request $request)
    {
        $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this deal market.'
        );

        $platformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());
        if ($request->filled('platform_id')) {
            $platformIds = [(int) $request->platform_id];
        }

        // Pass relevant filters to match Renewals engine
        $payload = $this->renewalService->buildOverview(
            [
                'search' => $request->get('search', ''),
                'bucket' => $request->get('bucket', 'all'),
                'status' => $request->get('status'),
                'platform_ids' => $platformIds,
                'high_risk' => $request->boolean('high_risk'),
                'cancellation_reason_code' => $request->get('cancellation_reason_code'),
                'include_untracked' => true,
            ],
            (int) $request->get('per_page', 25),
            $request->user()
        );

        return response()->json($payload);
    }

    public function show(Request $request, Deal $deal)
    {
        $this->authorizeDealAccess($request, $deal);
        $deal->load(['client', 'product', 'platform', 'assignedAgent', 'payment', 'lead']);
        return response()->json($deal);
    }

    public function update(Request $request, Deal $deal)
    {
        $this->authorizeDealAccess($request, $deal);

        $validated = $request->validate([
            'product_id' => 'sometimes|exists:products,id',
            'product_price_id' => 'nullable|exists:product_prices,id',
            'duration' => 'sometimes|in:weekly,biweekly,monthly,manual',
            'status' => 'sometimes|in:pending,awaiting_payment,paid,active,expired,cancelled,renewed',
        ]);

        $before = $deal->only(['product_id', 'plan_type', 'duration', 'status', 'amount']);

        if (array_key_exists('product_id', $validated) || array_key_exists('duration', $validated) || array_key_exists('product_price_id', $validated)) {
            $productId = (int) ($validated['product_id'] ?? $deal->product_id);
            if ($productId <= 0) {
                throw ValidationException::withMessages([
                    'product_id' => 'A product is required to update subscription pricing.',
                ]);
            }

            $product = $this->dealPaymentService->resolveScopedProduct($productId, (int) $deal->platform_id);
            $validated['product_id'] = $product->id;
            $validated['plan_type'] = $this->dealPaymentService->derivePlanTypeFromProduct($product);

            $productPriceId = isset($validated['product_price_id']) ? (int) $validated['product_price_id'] : null;
            $priceRow = $productPriceId ? $this->dealPaymentService->resolveScopedProductPrice($productPriceId, $product) : null;

            if ($priceRow) {
                $validated['amount'] = (float) $priceRow->price;
                $validated['duration'] = $this->dealPaymentService->mapDurationKeyToLegacy($priceRow->duration_key);
                $validated['duration_days'] = (int) $priceRow->duration_days;
                $validated['currency'] = strtoupper((string) $priceRow->currency);
                $validated['product_price_id'] = (int) $priceRow->id;
            } else {
                $effectiveCurrencies = $product->platform?->effectiveCurrencies()
                    ?? [strtoupper((string) ($product->currency ?: $deal->platform?->currency_code ?: 'KES'))];
                if (count($effectiveCurrencies) > 1) {
                    throw ValidationException::withMessages([
                        'product_price_id' => 'Select an explicit pricing option for multi-currency deals.',
                    ]);
                }

                $duration = $validated['duration'] ?? $deal->duration;
                $validated['amount'] = $this->dealPaymentService->resolveAmountForDuration($product, (string) $duration);
                $validated['currency'] = $product->currency ?: ($deal->platform?->currency_code ?: $deal->currency ?: 'KES');
                $validated['product_price_id'] = null;
                $validated['duration_days'] = $this->dealPaymentService->durationDaysForLegacyDuration((string) $duration);
            }
        }

        $deal->update($validated);

        TimelineEvent::create([
            'platform_id' => $deal->platform_id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'deal_updated',
            'actor_id' => $request->user()->id,
            'content' => [
                'before' => $before,
                'after' => $deal->only(['product_id', 'plan_type', 'duration', 'status', 'amount']),
            ],
            'created_at' => now(),
        ]);

        $deal->load(['client', 'product', 'platform', 'assignedAgent', 'payment', 'lead']);

        return response()->json($deal);
    }

    public function destroy(Request $request, Deal $deal)
    {
        $this->authorizeDealAccess($request, $deal);

        if ($deal->status === 'active') {
            return response()->json([
                'message' => 'Active deals cannot be deleted. Deactivate or cancel first.',
            ], 422);
        }

        $before = $deal->toArray();
        $platformId = $deal->platform_id;
        $dealId = $deal->id;

        $deal->delete();

        TimelineEvent::create([
            'platform_id' => $platformId,
            'entity_type' => 'deal',
            'entity_id' => $dealId,
            'event_type' => 'deal_deleted',
            'actor_id' => $request->user()->id,
            'content' => [
                'before' => [
                    'status' => $before['status'] ?? null,
                    'amount' => $before['amount'] ?? null,
                    'duration' => $before['duration'] ?? null,
                    'plan_type' => $before['plan_type'] ?? null,
                ],
            ],
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Deal deleted']);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'product_id' => 'required|exists:products,id',
            'product_price_id' => 'nullable|exists:product_prices,id',
            'duration' => 'nullable|in:weekly,biweekly,monthly,manual',
            'lead_id' => 'nullable|exists:leads,id',
            'custom_amount' => 'nullable|numeric|min:1',
            'custom_duration_days' => 'nullable|integer|min:1|max:365',
            'base_product_price_id' => 'nullable|exists:product_prices,id',
            'save_as_package' => 'nullable|boolean',
            'new_package_name' => 'required_if:save_as_package,true|nullable|string|min:2|max:64',
        ]);

        $client = Client::with('platform')->findOrFail($validated['client_id']);
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $client->platform_id,
            'You do not have access to this client market.'
        );

        $hasCustomAmount = array_key_exists('custom_amount', $validated) && $validated['custom_amount'] !== null && $validated['custom_amount'] !== '';
        $hasCustomDuration = array_key_exists('custom_duration_days', $validated) && $validated['custom_duration_days'] !== null && $validated['custom_duration_days'] !== '';
        if ($hasCustomAmount !== $hasCustomDuration) {
            throw ValidationException::withMessages([
                'custom_amount' => 'Custom amount and custom duration must be supplied together.',
            ]);
        }

        $saveAsPackage = (bool) ($validated['save_as_package'] ?? false);
        if ($saveAsPackage && (!$hasCustomAmount || !$hasCustomDuration)) {
            throw ValidationException::withMessages([
                'save_as_package' => 'Custom amount and duration are required before saving a package.',
            ]);
        }

        $baseProduct = $this->dealPaymentService->resolveScopedProduct((int) $validated['product_id'], (int) $client->platform_id);
        $baseProductPrice = null;
        if (!empty($validated['base_product_price_id'])) {
            $baseProductPrice = $this->dealPaymentService->resolveScopedProductPrice((int) $validated['base_product_price_id'], $baseProduct);
        }

        if ($hasCustomAmount && count($client->platform?->effectiveCurrencies() ?? []) > 1 && !$baseProductPrice) {
            throw ValidationException::withMessages([
                'base_product_price_id' => 'Select a base pricing option for multi-currency custom deals.',
            ]);
        }

        if ($hasCustomAmount && $saveAsPackage) {
            $deal = $this->dealPaymentService->createSalesPackageAndDeal(
                $client,
                (int) $baseProduct->id,
                $baseProductPrice ? (int) $baseProductPrice->id : null,
                (string) $validated['new_package_name'],
                (float) $validated['custom_amount'],
                (int) $validated['custom_duration_days'],
                (int) $request->user()->id,
                isset($validated['lead_id']) ? (int) $validated['lead_id'] : null
            );

            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                CrmAuditAction::PRODUCT_CREATE_SALES,
                'product',
                (int) $deal->product_id,
                null,
                [
                    'product_id' => (int) $deal->product_id,
                    'base_product_id' => (int) $baseProduct->id,
                    'base_product_price_id' => $baseProductPrice?->id,
                    'amount' => (float) $validated['custom_amount'],
                    'duration_days' => (int) $validated['custom_duration_days'],
                    'actor_id' => (int) $request->user()->id,
                ],
                'Sales-created package'
            );
        } elseif ($hasCustomAmount) {
            $deal = $this->dealPaymentService->createPendingDealWithCustomPricing(
                $client,
                (int) $baseProduct->id,
                $baseProductPrice ? (int) $baseProductPrice->id : null,
                (float) $validated['custom_amount'],
                (int) $validated['custom_duration_days'],
                (int) $request->user()->id,
                isset($validated['lead_id']) ? (int) $validated['lead_id'] : null
            );

            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                CrmAuditAction::DEAL_CREATE_CUSTOM,
                'deal',
                (int) $deal->id,
                null,
                [
                    'deal_id' => (int) $deal->id,
                    'base_product_id' => (int) $baseProduct->id,
                    'base_product_price_id' => $baseProductPrice?->id,
                    'base_amount' => $baseProductPrice ? (float) $baseProductPrice->price : null,
                    'custom_amount' => (float) $validated['custom_amount'],
                    'custom_duration_days' => (int) $validated['custom_duration_days'],
                ],
                'Created custom-priced deal'
            );
        } else {
            $deal = $this->dealPaymentService->createPendingDealFromCatalog(
                $client,
                (int) $baseProduct->id,
                isset($validated['product_price_id']) ? (int) $validated['product_price_id'] : null,
                $validated['duration'] ?? null,
                (int) $request->user()->id,
                isset($validated['lead_id']) ? (int) $validated['lead_id'] : null
            );
        }

        $deal->load(['client', 'product', 'platform']);
        return response()->json($deal, 201);
    }

    public function activate(Request $request, Deal $deal)
    {
        $this->authorizeDealAccess($request, $deal);
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
            'payment_method' => 'required|string|max:50',
            'payment_reference' => 'required_if:payment_method,manual|nullable|string|max:255',
            'payment_link_provider' => 'nullable|string|max:120',
            'free_trial_pin' => ['required_if:payment_method,free_trial', 'nullable', 'regex:/^\d{4,6}$/'],
            'discount_percentage' => 'nullable|numeric|min:1|max:99',
            'discount_payable_amount' => 'nullable|numeric|min:0.01',
            'discount_pin' => ['nullable', 'regex:/^\d{4,6}$/'],
            'approved_by' => 'nullable|string|max:255',
            'duration_days' => 'nullable|integer|min:1|max:365',
            'subscription_lifecycle' => 'nullable|in:new,renewal',
            'subscription_lifecycle_reason' => 'nullable|string|max:500',
        ]);

        if ($missingColumnsResponse = $this->missingSprint6DealColumnsResponse()) {
            return $missingColumnsResponse;
        }

        if ($deal->status === 'active') {
            return response()->json(['message' => 'Deal is already active'], 422);
        }

        $client = $deal->client;
        if (!$client) {
            return response()->json(['message' => 'Deal has no associated client'], 422);
        }

        $paymentMethod = (string) $validated['payment_method'];
        if ($methodGuard = $this->disallowedPaymentMethodResponse((int) $client->platform_id, 'activation', $paymentMethod)) {
            return $methodGuard;
        }
        if ($referenceGuard = $this->referenceRootGuardResponse((int) $client->platform_id, $paymentMethod, $validated['payment_reference'] ?? null)) {
            return $referenceGuard;
        }
        $discountBaseAmount = $this->discountBaseAmount($deal);
        $discountPayableAmount = $this->normalizedDiscountPayableAmount($validated['discount_payable_amount'] ?? null);
        $discountPercentage = $this->discountPercentageForRequest(
            $validated['discount_percentage'] ?? null,
            $discountPayableAmount,
            $discountBaseAmount
        );
        if ($discountPayableGuard = $this->discountPayableAmountResponse($discountPayableAmount, $discountBaseAmount)) {
            return $discountPayableGuard;
        }
        if ($discountGuard = $this->discountPermissionResponse(
            $request,
            $discountPercentage,
            $validated['discount_pin'] ?? null,
            $paymentMethod,
            (int) $client->platform_id
        )) {
            return $discountGuard;
        }
        if ($freeTrialGuard = $this->freeTrialPermissionResponse($request, $paymentMethod, $validated['free_trial_pin'] ?? null)) {
            return $freeTrialGuard;
        }
        $paymentLinkSelection = $paymentMethod === 'link'
            ? $this->dealPaymentService->resolvePaymentLinkProvider($client, $validated['payment_link_provider'] ?? null, $request->user())
            : null;
        $paymentLinkProvider = $paymentLinkSelection['provider'] ?? null;

        // Resolve duration days: explicit request param -> stored deal value -> catalog lookup -> legacy enum fallback
        $durationDays = isset($validated['duration_days']) ? (int) $validated['duration_days'] : 0;
        if ($durationDays < 1 && (int) ($deal->duration_days ?? 0) > 0) {
            $durationDays = (int) $deal->duration_days;
        }
        if ($durationDays < 1) {
            $durationDays = $this->dealPaymentService->resolveDurationDaysFromCatalog($deal);
        }
        if ($durationDays < 1) {
            $durationDays = 30;
        }

        $beforeState = [
            'deal_status' => $deal->status,
            'client_profile_status' => $client->profile_status,
            'payment_id' => $deal->payment_id,
            'amount' => (float) $deal->amount,
            'original_amount' => $deal->original_amount !== null ? (float) $deal->original_amount : null,
            'discount_percentage' => $deal->discount_percentage !== null ? (float) $deal->discount_percentage : null,
            'is_free_trial' => (bool) $deal->is_free_trial,
            'payment_method' => $paymentMethod,
        ];

        DB::beginTransaction();
        try {
            $platform = $client->platform ?? Platform::findOrFail($client->platform_id);
            $payment = null;
            $discountAudit = null;
            $lifecycle = $this->subscriptionLifecycleService->resolveForDeal(
                $deal,
                $validated['subscription_lifecycle'] ?? null,
                $validated['subscription_lifecycle_reason'] ?? null
            );

            $deal->forceFill(
                $this->subscriptionLifecycleService->toPersistenceAttributes($lifecycle)
            )->save();

            if ($paymentMethod !== 'free_trial') {
                $discountAudit = $this->syncDealDiscount(
                    $deal,
                    $discountPercentage,
                    (int) $request->user()->id,
                    $discountPayableAmount
                );
            }

            if ($paymentMethod === 'manual') {
                $payment = $this->dealPaymentService->createManualPaymentForDeal(
                    $deal,
                    $client,
                    (string) $validated['payment_reference'],
                    (int) $request->user()->id
                );
            } elseif ($paymentMethod === 'link') {
                $result = $this->dealPaymentService->startLinkPaymentForDeal(
                    $deal,
                    $client,
                    $request,
                    $paymentLinkProvider,
                    $paymentLinkSelection ?? [],
                    false,
                    $this->subscriptionLifecycleService->toPersistenceAttributes($lifecycle)
                );
                if ($discountAudit && $discountAudit['applied']) {
                    $this->recordDiscountAudit(
                        $request,
                        $deal,
                        $discountAudit['before'],
                        $discountAudit['after'],
                        ($validated['reason'] ?? null) ?: 'Applied discount during activation'
                    );
                }
                DB::commit();

                /** @var \App\Models\Payment $payment */
                $payment = $result['payment'];
                $deal = $result['deal'];
                $deal->load(['client', 'product', 'platform']);

                return response()->json([
                    'message' => $result['message'] ?? 'Payment initiated. Subscription will activate when payment succeeds.',
                    'deal' => $deal,
                    'payment' => $payment,
                ], 202);
            } elseif ($paymentMethod === 'stk') {
                $initiation = $this->dealPaymentService->initiatePaymentForDeal(
                    $deal,
                    $client,
                    $paymentMethod,
                    $request,
                    $paymentLinkProvider,
                    $paymentLinkSelection ?? [],
                    $this->subscriptionLifecycleService->toPersistenceAttributes($lifecycle)
                );
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

                $afterState = [
                    'deal_status' => 'awaiting_payment',
                    'payment_id' => $deal->payment_id,
                    'payment_reference' => $deal->payment_reference,
                    'amount' => (float) $deal->amount,
                    'original_amount' => $deal->original_amount !== null ? (float) $deal->original_amount : null,
                    'discount_percentage' => $deal->discount_percentage !== null ? (float) $deal->discount_percentage : null,
                    'payment_method' => $paymentMethod,
                    'subscription_lifecycle' => $deal->subscription_lifecycle,
                    'subscription_lifecycle_source' => $deal->subscription_lifecycle_source,
                    'payment_link_provider' => $paymentLinkProvider,
                    'requested_payment_link_provider' => $paymentLinkSelection['requested_provider'] ?? null,
                    'payment_link_provider_override_requested' => (bool) ($paymentLinkSelection['override_requested'] ?? false),
                    'payment_link_provider_override_applied' => (bool) ($paymentLinkSelection['override_applied'] ?? false),
                    'payment_link_provider_override_denied' => (bool) ($paymentLinkSelection['override_denied'] ?? false),
                ];

                $this->auditService->fromRequest(
                    $request,
                    (int) $client->platform_id,
                    CrmAuditAction::DEAL_ACTIVATE,
                    'deal',
                    (int) $deal->id,
                    $beforeState,
                    $afterState,
                    ($validated['reason'] ?? null) ?: 'Activation initiated pending payment'
                );

                TimelineEvent::create([
                    'platform_id' => $client->platform_id,
                    'entity_type' => 'deal',
                    'entity_id' => $deal->id,
                    'event_type' => 'deal_payment_initiated',
                    'actor_id' => $request->user()->id,
                    'content' => [
                        'payment_id' => $payment->id,
                        'payment_method' => $paymentMethod,
                    ],
                    'created_at' => now(),
                ]);

                if ($discountAudit && $discountAudit['applied']) {
                    $this->recordDiscountAudit(
                        $request,
                        $deal,
                        $discountAudit['before'],
                        $discountAudit['after'],
                        ($validated['reason'] ?? null) ?: 'Applied discount during activation'
                    );
                }

                DB::commit();

                $deal->load(['client', 'product', 'platform']);
                return response()->json([
                    'message' => $initiation['message'] ?? 'Payment initiated. Subscription will activate when payment succeeds.',
                    'deal' => $deal,
                    'payment' => $payment->fresh(['platform', 'product', 'client']),
                ], 202);
            }

            if ($paymentMethod === 'free_trial') {
                $payment = Payment::create([
                    'platform_id' => (int) $deal->platform_id,
                    'product_id' => $deal->product_id,
                    'deal_id' => (int) $deal->id,
                    'client_id' => (int) $client->id,
                    'phone' => $client->phone_normalized,
                    'amount' => 0,
                    'currency' => $deal->currency ?: ($platform->currency_code ?? 'KES'),
                    'transaction_uuid' => 'free_trial_' . $deal->id . '_' . now()->timestamp,
                    'transaction_reference' => 'FREE-TRIAL-' . $deal->id,
                    'status' => 'completed',
                    'duration' => $deal->duration,
                    'subscription_lifecycle' => $lifecycle['subscription_lifecycle'],
                    'subscription_lifecycle_source' => $lifecycle['subscription_lifecycle_source'],
                    'subscription_lifecycle_reason' => $lifecycle['subscription_lifecycle_reason'],
                    'raw_payload' => [
                        'source' => 'deal_free_trial',
                        'deal_id' => (int) $deal->id,
                        'approval_mode' => 'pin',
                    ],
                    'match_confidence' => 'manual',
                    'confirmed_by' => (int) $request->user()->id,
                    'confirmed_at' => now(),
                ]);
            }

            $isFreeTrial = $paymentMethod === 'free_trial';
            $deal = $this->subscriptionProvisioningService->activateDeal($deal, [
                'payment' => $payment,
                'payment_method' => $paymentMethod,
                'duration_days' => $durationDays,
                'payment_reference' => $payment?->transaction_reference
                    ?? ($paymentMethod === 'manual' ? (string) $validated['payment_reference'] : null),
                'is_free_trial' => $isFreeTrial,
                'free_trial_approved_by' => null,
                'actor_id' => (int) $request->user()->id,
                'emit_profile_activated_timeline' => true,
                'emit_deal_activated_timeline' => true,
            ]);
            $client = $deal->client;

            $afterState = [
                'deal_status' => 'active',
                'client_profile_status' => $client->profile_status,
                'expires_at' => $deal->expires_at->toDateTimeString(),
                'payment_id' => $deal->payment_id,
                'payment_reference' => $deal->payment_reference,
                'amount' => (float) $deal->amount,
                'original_amount' => $deal->original_amount !== null ? (float) $deal->original_amount : null,
                'discount_percentage' => $deal->discount_percentage !== null ? (float) $deal->discount_percentage : null,
                'payment_method' => $paymentMethod,
                'payment_link_provider' => $paymentLinkProvider,
                'is_free_trial' => (bool) $deal->is_free_trial,
            ];

            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                CrmAuditAction::DEAL_ACTIVATE,
                'deal',
                (int) $deal->id,
                $beforeState,
                $afterState,
                ($validated['reason'] ?? null) ?: 'Activated via CRM flow'
            );

            if ($isFreeTrial) {
                $this->auditService->fromRequest(
                    $request,
                    (int) $client->platform_id,
                    CrmAuditAction::DEAL_FREE_TRIAL,
                    'deal',
                    (int) $deal->id,
                    null,
                    [
                        'approval_mode' => 'pin',
                        'duration_days' => $durationDays,
                    ],
                    ($validated['reason'] ?? null) ?: 'Free trial activation from CRM flow'
                );
            }

            if ($discountAudit && $discountAudit['applied']) {
                $this->recordDiscountAudit(
                    $request,
                    $deal,
                    $discountAudit['before'],
                    $discountAudit['after'],
                    ($validated['reason'] ?? null) ?: 'Applied discount during activation'
                );
            }

            DB::commit();

            $deal->load(['client', 'product', 'platform']);
            return response()->json($deal);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Deal activation failed', [
                'deal_id' => $deal->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Activation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function deactivate(Request $request, Deal $deal)
    {
        $this->authorizeDealAccess($request, $deal);

        $client = $deal->client;
        if (!$client) {
            return response()->json(['message' => 'Deal has no associated client'], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
            'reason_code' => 'nullable|string|in:' . implode(',', $this->deactivationReasonValues()),
            'reason_notes' => 'nullable|string|max:500',
            'linked_payment_action' => 'nullable|string|in:' . implode(',', $this->linkedPaymentActionValues()),
            'notify_client' => 'nullable|boolean',
            'notification_message' => 'nullable|string|max:500',
            'notification_template_id' => 'nullable|integer|exists:templates,id',
        ]);

        if (
            trim((string) ($validated['reason_code'] ?? '')) === ''
            && trim((string) ($validated['reason'] ?? '')) === ''
        ) {
            throw ValidationException::withMessages([
                'reason_code' => 'A structured reason code or legacy reason is required.',
            ]);
        }

        $deactivationRequest = $this->buildDeactivationRequest($validated);

        $beforeState = [
            'deal_status' => $deal->status,
            'cancellation_reason_code' => $deal->cancellation_reason_code,
            'cancellation_notes' => $deal->cancellation_notes,
            'cancelled_payment_id' => $deal->cancelled_payment_id,
            'client_profile_status' => $client->profile_status,
            'client_is_high_risk' => (bool) $client->is_high_risk,
            'client_risk_reason_code' => $client->risk_reason_code,
        ];

        DB::beginTransaction();
        try {
            $deal = $this->subscriptionDeactivationService->deactivateDeal(
                $deal,
                $deactivationRequest,
                optional($request->user())->id
            );
            $client = $deal->client?->fresh() ?? $client->fresh();
            $cancelledPayment = $deal->cancelledPayment()->first();

            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                CrmAuditAction::DEAL_DEACTIVATE,
                'deal',
                (int) $deal->id,
                $beforeState,
                [
                    'deal_status' => 'cancelled',
                    'cancellation_reason_code' => $deal->cancellation_reason_code,
                    'cancellation_notes' => $deal->cancellation_notes,
                    'cancelled_payment_id' => $deal->cancelled_payment_id,
                    'client_profile_status' => $client->profile_status,
                    'client_is_high_risk' => (bool) $client->is_high_risk,
                    'client_risk_reason_code' => $client->risk_reason_code,
                    'linked_payment_action' => $deactivationRequest->resolvedLinkedPaymentAction()->value,
                    'payment_resolution_code' => $cancelledPayment?->resolution_code,
                ],
                $deactivationRequest->auditReason()
            );

            DB::commit();

            if ($request->boolean('notify_client')) {
                $message = $this->resolveDeactivationMessage(
                    $client,
                    $request->input('notification_message'),
                    $request->input('notification_template_id')
                );

                if ($message) {
                    $this->notificationService->sendSmsToClient($client, $message, [
                        'purpose' => 'deal_deactivate_notice',
                        'deal_id' => $deal->id,
                    ]);
                }
            }

            $deal->load(['client', 'product', 'platform']);
            return response()->json($deal);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Deal deactivation failed', [
                'deal_id' => $deal->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Deactivation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function extend(Request $request, Deal $deal)
    {
        $this->authorizeDealAccess($request, $deal);

        if ($deal->status !== 'active') {
            return response()->json(['message' => 'Only active deals can be extended'], 422);
        }

        $validated = $request->validate([
            'additional_days' => 'required|integer|min:1|max:365',
            'reason' => 'required|string|max:500',
            'payment_method' => 'required|string|max:50',
            'payment_reference' => 'required_if:payment_method,manual|nullable|string|max:255',
            'payment_link_provider' => 'nullable|string|max:120',
            'free_trial_pin' => ['required_if:payment_method,free_trial', 'nullable', 'regex:/^\d{4,6}$/'],
            'discount_percentage' => 'nullable|numeric|min:1|max:99',
            'discount_payable_amount' => 'nullable|numeric|min:0.01',
            'discount_pin' => ['nullable', 'regex:/^\d{4,6}$/'],
            'approved_by' => 'nullable|string|max:255',
            'subscription_lifecycle' => 'nullable|in:new,renewal',
            'subscription_lifecycle_reason' => 'nullable|string|max:500',
        ]);

        if ($missingColumnsResponse = $this->missingSprint6DealColumnsResponse()) {
            return $missingColumnsResponse;
        }

        $client = $deal->client;
        if (!$client) {
            return response()->json(['message' => 'Deal has no associated client'], 422);
        }

        $paymentMethod = (string) $validated['payment_method'];
        if ($methodGuard = $this->disallowedPaymentMethodResponse((int) $client->platform_id, 'renewal', $paymentMethod)) {
            return $methodGuard;
        }
        if ($referenceGuard = $this->referenceRootGuardResponse((int) $client->platform_id, $paymentMethod, $validated['payment_reference'] ?? null)) {
            return $referenceGuard;
        }
        $discountBaseAmount = $this->discountBaseAmount($deal);
        $discountPayableAmount = $this->normalizedDiscountPayableAmount($validated['discount_payable_amount'] ?? null);
        $discountPercentage = $this->discountPercentageForRequest(
            $validated['discount_percentage'] ?? null,
            $discountPayableAmount,
            $discountBaseAmount
        );
        if ($discountPayableGuard = $this->discountPayableAmountResponse($discountPayableAmount, $discountBaseAmount)) {
            return $discountPayableGuard;
        }
        if ($discountGuard = $this->discountPermissionResponse(
            $request,
            $discountPercentage,
            $validated['discount_pin'] ?? null,
            $paymentMethod,
            (int) $client->platform_id
        )) {
            return $discountGuard;
        }
        if ($freeTrialGuard = $this->freeTrialPermissionResponse($request, $paymentMethod, $validated['free_trial_pin'] ?? null)) {
            return $freeTrialGuard;
        }
        $paymentLinkSelection = $paymentMethod === 'link'
            ? $this->dealPaymentService->resolvePaymentLinkProvider($client, $validated['payment_link_provider'] ?? null, $request->user())
            : null;
        $paymentLinkProvider = $paymentLinkSelection['provider'] ?? null;

        $beforeState = [
            'expires_at' => $deal->expires_at?->toDateTimeString(),
            'payment_id' => $deal->payment_id,
            'payment_reference' => $deal->payment_reference,
            'amount' => (float) $deal->amount,
            'original_amount' => $deal->original_amount !== null ? (float) $deal->original_amount : null,
            'discount_percentage' => $deal->discount_percentage !== null ? (float) $deal->discount_percentage : null,
            'payment_method' => $paymentMethod,
        ];

        DB::beginTransaction();
        try {
            $platform = $client->platform ?? Platform::findOrFail($client->platform_id);
            $wpSync = WpSyncService::forPlatform($client->platform_id);
            $wpSync->extendClient($client->wp_post_id, (int) $validated['additional_days']);
            $discountAudit = null;
            $lifecycle = $this->subscriptionLifecycleService->resolveForClient(
                $client,
                (int) $client->platform_id,
                $validated['subscription_lifecycle'] ?? null,
                $validated['subscription_lifecycle_reason'] ?? null,
                ['force_lifecycle' => SubscriptionLifecycleService::LIFECYCLE_RENEWAL]
            );

            if ($paymentMethod !== 'free_trial') {
                $discountAudit = $this->syncDealDiscount(
                    $deal,
                    $discountPercentage,
                    (int) $request->user()->id,
                    $discountPayableAmount
                );
            }

            $payment = null;
            if ($paymentMethod === 'manual') {
                $payment = $this->dealPaymentService->createManualPaymentForDeal(
                    $deal,
                    $client,
                    (string) $validated['payment_reference'],
                    (int) $request->user()->id,
                    $this->subscriptionLifecycleService->toPersistenceAttributes($lifecycle)
                );
            } elseif (in_array($paymentMethod, ['stk', 'link'], true)) {
                $initiation = $this->dealPaymentService->initiatePaymentForDeal(
                    $deal,
                    $client,
                    $paymentMethod,
                    $request,
                    $paymentLinkProvider,
                    $paymentLinkSelection ?? [],
                    $this->subscriptionLifecycleService->toPersistenceAttributes($lifecycle)
                );
                if (!($initiation['success'] ?? false)) {
                    throw new \RuntimeException((string) ($initiation['message'] ?? 'Payment initiation failed.'));
                }

                /** @var \App\Models\Payment|null $payment */
                $payment = $initiation['payment'] ?? null;
            }

            $newExpiry = ($deal->expires_at ?? now())->copy()->addDays((int) $validated['additional_days']);
            $deal->update([
                'expires_at' => $newExpiry,
                'payment_id' => $paymentMethod === 'manual'
                    ? ($payment?->id ?? $deal->payment_id)
                    : $deal->payment_id,
                'payment_reference' => $payment?->transaction_reference
                    ?? ($paymentMethod === 'manual' ? (string) $validated['payment_reference'] : $deal->payment_reference),
                'is_free_trial' => $paymentMethod === 'free_trial' ? true : (bool) $deal->is_free_trial,
                'free_trial_approved_by' => $paymentMethod === 'free_trial' ? null : $deal->free_trial_approved_by,
            ]);

            $syncService = new ClientSyncService($platform);
            $syncService->syncOne($client->wp_post_id);

            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                CrmAuditAction::DEAL_EXTEND,
                'deal',
                (int) $deal->id,
                $beforeState,
                [
                    'expires_at' => $newExpiry->toDateTimeString(),
                    'payment_id' => $deal->payment_id,
                    'payment_reference' => $deal->payment_reference,
                    'amount' => (float) $deal->amount,
                    'original_amount' => $deal->original_amount !== null ? (float) $deal->original_amount : null,
                    'discount_percentage' => $deal->discount_percentage !== null ? (float) $deal->discount_percentage : null,
                    'payment_method' => $paymentMethod,
                    'extension_payment_lifecycle' => $payment?->subscription_lifecycle,
                    'payment_link_provider' => $paymentLinkProvider,
                    'requested_payment_link_provider' => $paymentLinkSelection['requested_provider'] ?? null,
                    'payment_link_provider_override_requested' => (bool) ($paymentLinkSelection['override_requested'] ?? false),
                    'payment_link_provider_override_applied' => (bool) ($paymentLinkSelection['override_applied'] ?? false),
                    'payment_link_provider_override_denied' => (bool) ($paymentLinkSelection['override_denied'] ?? false),
                    'extension_payment_id' => $payment?->id,
                ],
                (string) $validated['reason']
            );

            if ($paymentMethod === 'free_trial') {
                $this->auditService->fromRequest(
                    $request,
                    (int) $client->platform_id,
                    CrmAuditAction::DEAL_FREE_TRIAL,
                    'deal',
                    (int) $deal->id,
                    null,
                    [
                        'approval_mode' => 'pin',
                        'additional_days' => (int) $validated['additional_days'],
                    ],
                    (string) $validated['reason']
                );
            }

            TimelineEvent::create([
                'platform_id' => $client->platform_id,
                'entity_type' => 'client',
                'entity_id' => $client->id,
                'event_type' => 'deal_extended',
                'actor_id' => $request->user()->id,
                'content' => [
                    'deal_id' => $deal->id,
                    'additional_days' => (int) $validated['additional_days'],
                    'new_expires_at' => $newExpiry->toDateTimeString(),
                    'payment_method' => $paymentMethod,
                    'extension_payment_id' => $payment?->id,
                    ],
                    'created_at' => now(),
                ]);

            if ($discountAudit && $discountAudit['applied']) {
                $this->recordDiscountAudit(
                    $request,
                    $deal,
                    $discountAudit['before'],
                    $discountAudit['after'],
                    (string) $validated['reason']
                );
            }

            DB::commit();

            $deal->load(['client', 'product', 'platform']);
            return response()->json($deal);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Extension failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function renew(Request $request, Deal $deal)
    {
        $this->authorizeDealAccess($request, $deal);

        $validated = $request->validate([
            'additional_days' => 'required|integer|min:1|max:365',
            'reason' => 'required|string|max:500',
            'payment_method' => 'required|string|max:50',
            'payment_reference' => 'required_if:payment_method,manual|nullable|string|max:255',
            'payment_link_provider' => 'nullable|string|max:120',
            'free_trial_pin' => ['required_if:payment_method,free_trial', 'nullable', 'regex:/^\d{4,6}$/'],
            'discount_percentage' => 'nullable|numeric|min:1|max:99',
            'discount_payable_amount' => 'nullable|numeric|min:0.01',
            'discount_pin' => ['nullable', 'regex:/^\d{4,6}$/'],
            'approved_by' => 'nullable|string|max:255',
            'subscription_lifecycle' => 'nullable|in:new,renewal',
            'subscription_lifecycle_reason' => 'nullable|string|max:500',
        ]);

        if ($missingColumnsResponse = $this->missingSprint6DealColumnsResponse()) {
            return $missingColumnsResponse;
        }

        if (!in_array($deal->status, ['expired', 'cancelled'], true)) {
            return response()->json([
                'message' => 'Only expired or cancelled subscriptions can be renewed.',
            ], 422);
        }

        $client = $deal->client;
        if (!$client) {
            return response()->json(['message' => 'Deal has no associated client'], 422);
        }

        $paymentMethod = (string) $validated['payment_method'];
        if ($methodGuard = $this->disallowedPaymentMethodResponse((int) $client->platform_id, 'renewal', $paymentMethod)) {
            return $methodGuard;
        }
        if ($referenceGuard = $this->referenceRootGuardResponse((int) $client->platform_id, $paymentMethod, $validated['payment_reference'] ?? null)) {
            return $referenceGuard;
        }
        $baseAmount = $deal->original_amount !== null
            ? (float) $deal->original_amount
            : (float) $deal->amount;
        $discountPayableAmount = $this->normalizedDiscountPayableAmount($validated['discount_payable_amount'] ?? null);
        $discountPercentage = $this->discountPercentageForRequest(
            $validated['discount_percentage'] ?? null,
            $discountPayableAmount,
            $baseAmount
        );
        if ($discountPayableGuard = $this->discountPayableAmountResponse($discountPayableAmount, $baseAmount)) {
            return $discountPayableGuard;
        }
        if ($discountGuard = $this->discountPermissionResponse(
            $request,
            $discountPercentage,
            $validated['discount_pin'] ?? null,
            $paymentMethod,
            (int) $client->platform_id
        )) {
            return $discountGuard;
        }
        if ($freeTrialGuard = $this->freeTrialPermissionResponse($request, $paymentMethod, $validated['free_trial_pin'] ?? null)) {
            return $freeTrialGuard;
        }
        $paymentLinkSelection = $paymentMethod === 'link'
            ? $this->dealPaymentService->resolvePaymentLinkProvider($client, $validated['payment_link_provider'] ?? null, $request->user())
            : null;
        $paymentLinkProvider = $paymentLinkSelection['provider'] ?? null;
        $isFreeTrial = $paymentMethod === 'free_trial';

        $beforeState = [
            'old_deal_id' => (int) $deal->id,
            'old_status' => $deal->status,
            'expires_at' => $deal->expires_at?->toDateTimeString(),
            'payment_id' => $deal->payment_id,
            'is_free_trial' => (bool) $deal->is_free_trial,
            'payment_method' => $paymentMethod,
        ];

        DB::beginTransaction();
        try {
            $platform = $client->platform ?? Platform::findOrFail($client->platform_id);
            $additionalDays = (int) $validated['additional_days'];
            $activatesImmediately = in_array($paymentMethod, ['manual', 'free_trial'], true);
            $lifecycle = $this->subscriptionLifecycleService->resolveForClient(
                $client,
                (int) $client->platform_id,
                $validated['subscription_lifecycle'] ?? null,
                $validated['subscription_lifecycle_reason'] ?? null,
                ['force_lifecycle' => SubscriptionLifecycleService::LIFECYCLE_RENEWAL]
            );

            $newDeal = Deal::create([
                'platform_id' => $deal->platform_id,
                'client_id' => $deal->client_id,
                'lead_id' => $deal->lead_id,
                'product_id' => $deal->product_id,
                'plan_type' => $deal->plan_type,
                'amount' => round($baseAmount, 2),
                'currency' => $deal->currency,
                'duration' => $deal->duration,
                'status' => $activatesImmediately ? 'active' : 'awaiting_payment',
                'activated_at' => $activatesImmediately ? now() : null,
                'expires_at' => $activatesImmediately ? now()->addDays($additionalDays) : null,
                'assigned_to' => $deal->assigned_to,
                'is_free_trial' => $isFreeTrial,
                'free_trial_approved_by' => null,
                'discount_percentage' => null,
                'original_amount' => null,
                'discount_approved_by' => null,
                'payment_reference' => $paymentMethod === 'manual' ? (string) $validated['payment_reference'] : null,
            ] + $this->subscriptionLifecycleService->toPersistenceAttributes($lifecycle));
            $discountAudit = null;

            if ($paymentMethod !== 'free_trial') {
                $discountAudit = $this->syncDealDiscount(
                    $newDeal,
                    $discountPercentage,
                    (int) $request->user()->id,
                    $discountPayableAmount
                );
            }

            $payment = null;
            if ($paymentMethod === 'manual') {
                $payment = $this->dealPaymentService->createManualPaymentForDeal(
                    $newDeal,
                    $client,
                    (string) $validated['payment_reference'],
                    (int) $request->user()->id
                );
                $newDeal->update([
                    'payment_id' => $payment->id,
                    'payment_reference' => $payment->transaction_reference,
                ]);
            } elseif (in_array($paymentMethod, ['stk', 'link'], true)) {
                $initiation = $this->dealPaymentService->initiatePaymentForDeal(
                    $newDeal,
                    $client,
                    $paymentMethod,
                    $request,
                    $paymentLinkProvider,
                    $paymentLinkSelection ?? [],
                    $this->subscriptionLifecycleService->toPersistenceAttributes($lifecycle)
                );
                if (!($initiation['success'] ?? false)) {
                    throw new \RuntimeException((string) ($initiation['message'] ?? 'Payment initiation failed.'));
                }

                /** @var \App\Models\Payment $payment */
                $payment = $initiation['payment'];
                $newDeal->update([
                    'status' => 'awaiting_payment',
                    'payment_id' => $payment->id,
                    'payment_reference' => $payment->transaction_reference,
                ]);
            }

            if ($activatesImmediately) {
                $wpSync = WpSyncService::forPlatform($client->platform_id);
                $wpSync->activateClient(
                    $client->wp_post_id,
                    $newDeal->plan_type,
                    $additionalDays,
                    $newDeal->id
                );
                $deal->update(['status' => 'renewed']);

                $syncService = new ClientSyncService($platform);
                $syncService->syncOne($client->wp_post_id);
                $client->refresh();
            }

            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                CrmAuditAction::DEAL_RENEW,
                'deal',
                (int) $deal->id,
                $beforeState,
                [
                    'old_deal_id' => (int) $deal->id,
                    'old_status' => $deal->status,
                    'new_deal_id' => (int) $newDeal->id,
                    'new_status' => $newDeal->status,
                    'new_expires_at' => optional($newDeal->expires_at)->toDateTimeString(),
                    'payment_id' => $newDeal->payment_id,
                    'amount' => (float) $newDeal->amount,
                    'original_amount' => $newDeal->original_amount !== null ? (float) $newDeal->original_amount : null,
                    'discount_percentage' => $newDeal->discount_percentage !== null ? (float) $newDeal->discount_percentage : null,
                    'payment_method' => $paymentMethod,
                    'payment_link_provider' => $paymentLinkProvider,
                    'requested_payment_link_provider' => $paymentLinkSelection['requested_provider'] ?? null,
                    'payment_link_provider_override_requested' => (bool) ($paymentLinkSelection['override_requested'] ?? false),
                    'payment_link_provider_override_applied' => (bool) ($paymentLinkSelection['override_applied'] ?? false),
                    'payment_link_provider_override_denied' => (bool) ($paymentLinkSelection['override_denied'] ?? false),
                    'is_free_trial' => (bool) $newDeal->is_free_trial,
                ],
                (string) $validated['reason']
            );

            if ($isFreeTrial) {
                $this->auditService->fromRequest(
                    $request,
                    (int) $client->platform_id,
                    CrmAuditAction::DEAL_FREE_TRIAL,
                    'deal',
                    (int) $newDeal->id,
                    null,
                    [
                        'approval_mode' => 'pin',
                        'duration_days' => $additionalDays,
                    ],
                    (string) $validated['reason']
                );
            }

            if ($discountAudit && $discountAudit['applied']) {
                $this->recordDiscountAudit(
                    $request,
                    $newDeal,
                    $discountAudit['before'],
                    $discountAudit['after'],
                    (string) $validated['reason']
                );
            }

            TimelineEvent::create([
                'platform_id' => $client->platform_id,
                'entity_type' => 'deal',
                'entity_id' => $deal->id,
                'event_type' => 'deal_renewed',
                'actor_id' => $request->user()->id,
                'content' => [
                    'old_deal_id' => $deal->id,
                    'new_deal_id' => $newDeal->id,
                    'additional_days' => $additionalDays,
                    'new_expires_at' => optional($newDeal->expires_at)->toDateTimeString(),
                    'payment_method' => $paymentMethod,
                ],
                'created_at' => now(),
            ]);

            TimelineEvent::create([
                'platform_id' => $client->platform_id,
                'entity_type' => 'deal',
                'entity_id' => $newDeal->id,
                'event_type' => $activatesImmediately ? 'deal_activated' : 'deal_payment_initiated',
                'actor_id' => $request->user()->id,
                'content' => [
                    'renewed_from_deal_id' => $deal->id,
                    'additional_days' => $additionalDays,
                    'payment_method' => $paymentMethod,
                    'payment_id' => $payment?->id,
                ],
                'created_at' => now(),
            ]);

            DB::commit();

            $newDeal->load(['client', 'product', 'platform']);
            if ($activatesImmediately) {
                return response()->json($newDeal);
            }

            return response()->json([
                'message' => 'Renewal initiated and waiting for payment confirmation.',
                'deal' => $newDeal,
                'payment' => $payment?->fresh(['platform', 'product', 'client']),
            ], 202);
        } catch (\Throwable $exception) {
            DB::rollBack();
            Log::error('Deal renewal failed', [
                'deal_id' => $deal->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Renewal failed: ' . $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * @deprecated Kept for backwards compatibility while external clients move to payment_method contract.
     */
    private function resolveVerifiedPaymentForDeal(Deal $deal, $paymentId = null): ?Payment
    {
        $query = Payment::query()
            ->where('platform_id', $deal->platform_id)
            ->whereIn('status', Payment::SUCCESSFUL_STATUSES);

        if ($paymentId) {
            $payment = $query->where('id', (int) $paymentId)->first();
        } else {
            $payment = $query
                ->where('client_id', $deal->client_id)
                ->orderByDesc('created_at')
                ->first();
        }

        if (!$payment) {
            return null;
        }

        if ($payment->client_id && (int) $payment->client_id !== (int) $deal->client_id) {
            return null;
        }

        if ($payment->product_id && $deal->product_id && (int) $payment->product_id !== (int) $deal->product_id) {
            return null;
        }

        if ($deal->amount !== null && abs((float) $payment->amount - (float) $deal->amount) > 0.01) {
            return null;
        }

        if ($payment->deal_id && (int) $payment->deal_id !== (int) $deal->id) {
            return null;
        }

        return $payment;
    }

    private function freeTrialPermissionResponse(Request $request, string $paymentMethod, ?string $freeTrialPin = null): ?\Illuminate\Http\JsonResponse
    {
        if ($paymentMethod !== 'free_trial') {
            return null;
        }

        if (!$this->walletSettingsService->freeTrialPinIsConfigured()) {
            return response()->json([
                'message' => 'Free-trial PIN is not configured. Ask an admin to set it in Settings first.',
            ], 409);
        }

        if ($freeTrialPin !== null && $this->walletSettingsService->verifyFreeTrialPin($freeTrialPin)) {
            return null;
        }

        return response()->json([
            'message' => 'Free-trial PIN is invalid.',
        ], 422);
    }

    private function discountPermissionResponse(
        Request $request,
        ?float $discountPercentage,
        ?string $discountPin,
        string $paymentMethod,
        int $platformId
    ): ?\Illuminate\Http\JsonResponse {
        if ($discountPercentage === null || $discountPercentage <= 0) {
            return null;
        }

        if ($paymentMethod === 'free_trial') {
            return response()->json([
                'message' => 'Discounts cannot be applied to free trials.',
            ], 422);
        }

        if (!$this->walletSettingsService->discountPinIsConfigured()) {
            return response()->json([
                'message' => 'Discount PIN is not configured. Ask an admin to set it in Settings first.',
            ], 409);
        }

        if ($discountPin === null || trim($discountPin) === '' || !$this->walletSettingsService->verifyDiscountPin($discountPin)) {
            return response()->json([
                'message' => 'Discount PIN is invalid.',
            ], 422);
        }

        $maxByPlatform = (array) data_get($this->walletSettingsService->getDiscountConfig(), 'max_percentage_by_platform', []);
        $maxPercentage = isset($maxByPlatform[(string) $platformId])
            ? (float) $maxByPlatform[(string) $platformId]
            : 0.0;

        if ($discountPercentage > $maxPercentage) {
            return response()->json([
                'message' => "Discount exceeds the configured market maximum of {$maxPercentage}%.",
            ], 422);
        }

        return null;
    }

    private function missingSprint6DealColumnsResponse(): ?\Illuminate\Http\JsonResponse
    {
        static $missingColumns = null;
        if ($missingColumns === null) {
            $missingColumns = [];
            foreach (['is_free_trial', 'free_trial_approved_by', 'payment_reference', 'discount_percentage', 'original_amount', 'discount_approved_by', 'discount_source'] as $column) {
                if (!Schema::hasColumn('deals', $column)) {
                    $missingColumns[] = $column;
                }
            }
        }

        if (empty($missingColumns)) {
            return null;
        }

        return response()->json([
            'message' => 'Sprint 6 migrations are pending. Run `php artisan migrate` before using activation, extension, or renewal workflows.',
            'missing_columns' => $missingColumns,
        ], 409);
    }

    private function resolveDeactivationMessage(Client $client, ?string $customMessage = null, $templateId = null): ?string
    {
        $custom = trim((string) $customMessage);
        if ($custom !== '') {
            return $custom;
        }

        if (!empty($templateId)) {
            $template = Template::query()
                ->where('id', (int) $templateId)
                ->where('channel', 'sms')
                ->first();

            if ($template && trim((string) $template->body) !== '') {
                return str_replace(
                    ['{{client_name}}', '{{name}}'],
                    [$client->name ?: 'Client', $client->name ?: 'Client'],
                    (string) $template->body
                );
            }
        }

        $name = $client->name ?: 'there';
        return "Hi {$name}, your subscription has been deactivated. Contact support if this is unexpected.";
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function buildDeactivationRequest(array $validated): DeactivationRequest
    {
        $legacyReason = trim((string) ($validated['reason'] ?? ''));
        $reasonCode = trim((string) ($validated['reason_code'] ?? ''));
        $reasonNotes = trim((string) ($validated['reason_notes'] ?? ''));
        $linkedPaymentAction = trim((string) ($validated['linked_payment_action'] ?? ''));

        if ($reasonCode === '') {
            $reasonCode = DealDeactivationReason::OTHER->value;
            $reasonNotes = $reasonNotes !== '' ? $reasonNotes : ($legacyReason !== '' ? $legacyReason : null);
        }

        return new DeactivationRequest(
            DealDeactivationReason::from($reasonCode),
            $reasonNotes !== '' ? $reasonNotes : null,
            $linkedPaymentAction !== '' ? LinkedPaymentAction::from($linkedPaymentAction) : null
        );
    }

    /**
     * @return list<string>
     */
    private function deactivationReasonValues(): array
    {
        return array_map(
            static fn (DealDeactivationReason $reason): string => $reason->value,
            DealDeactivationReason::cases()
        );
    }

    /**
     * @return list<string>
     */
    private function linkedPaymentActionValues(): array
    {
        return array_map(
            static fn (LinkedPaymentAction $action): string => $action->value,
            LinkedPaymentAction::cases()
        );
    }

    private function authorizeDealAccess(Request $request, Deal $deal): void
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $deal->platform_id,
            'You do not have access to this deal market.'
        );
    }

    private function disallowedPaymentMethodResponse(int $platformId, string $surface, string $paymentMethod): ?\Illuminate\Http\JsonResponse
    {
        $normalizedMethod = strtolower(trim($paymentMethod));
        $policy = $this->dealPaymentService->marketBillingMethodPolicy($platformId);
        $allowedMethods = data_get($policy, $surface . '.crm_methods', []);

        if (in_array($normalizedMethod, $allowedMethods, true)) {
            return null;
        }

        return response()->json([
            'message' => 'This payment method is not allowed for this market.',
            'allowed_methods' => $allowedMethods,
            'policy' => $policy,
        ], 422);
    }

    private function referenceRootGuardResponse(
        int $platformId,
        string $paymentMethod,
        ?string $paymentReference = null
    ): ?\Illuminate\Http\JsonResponse {
        if (strtolower(trim($paymentMethod)) !== 'manual') {
            return null;
        }

        $reference = trim((string) $paymentReference);
        if ($reference === '') {
            return null;
        }

        $conflict = $this->manualPaymentBundleService->findReferenceConflict($platformId, $reference);
        if (!$conflict) {
            return null;
        }

        return response()->json([
            'message' => 'This manual reference root is already in use. Open the shared manual payment flow instead.',
            'reference_root' => $conflict['reference_root'] ?? null,
            'existing_bundle_id' => $conflict['existing_bundle_id'] ?? null,
            'existing_payment_id' => $conflict['existing_payment_id'] ?? null,
            'status' => $conflict['status'] ?? null,
        ], 409);
    }

    private function normalizedDiscountPercentage(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $percentage = round((float) $value, 2);

        return $percentage > 0 ? $percentage : null;
    }

    private function normalizedDiscountPayableAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $amount = round((float) $value, 2);

        return $amount > 0 ? $amount : null;
    }

    private function discountBaseAmount(Deal $deal): float
    {
        return $deal->original_amount !== null
            ? (float) $deal->original_amount
            : (float) $deal->amount;
    }

    private function discountPercentageForRequest(mixed $discountPercentage, ?float $discountPayableAmount, float $baseAmount): ?float
    {
        if ($discountPayableAmount !== null && $baseAmount > 0) {
            return round((($baseAmount - $discountPayableAmount) / $baseAmount) * 100, 6);
        }

        return $this->normalizedDiscountPercentage($discountPercentage);
    }

    private function discountPayableAmountResponse(?float $discountPayableAmount, float $baseAmount): ?\Illuminate\Http\JsonResponse
    {
        if ($discountPayableAmount === null) {
            return null;
        }

        if ($baseAmount <= 0) {
            return response()->json([
                'message' => 'A valid base amount is required before applying a final payable discount.',
            ], 422);
        }

        $minimumPayableAmount = round($baseAmount * 0.01, 2);
        $maximumPayableAmount = round($baseAmount * 0.99, 2);

        if ($discountPayableAmount < $minimumPayableAmount) {
            return response()->json([
                'message' => 'Final payable amount is below the 99% discount limit.',
                'minimum_payable_amount' => $minimumPayableAmount,
            ], 422);
        }

        if ($discountPayableAmount > $maximumPayableAmount) {
            return response()->json([
                'message' => 'Final payable amount must apply at least a 1% discount.',
                'maximum_payable_amount' => $maximumPayableAmount,
            ], 422);
        }

        return null;
    }

    private function syncDealDiscount(Deal $deal, ?float $discountPercentage, int $actorId, ?float $discountPayableAmount = null): array
    {
        $before = [
            'amount' => (float) $deal->amount,
            'original_amount' => $deal->original_amount !== null ? (float) $deal->original_amount : null,
            'discount_percentage' => $deal->discount_percentage !== null ? (float) $deal->discount_percentage : null,
            'discount_approved_by' => $deal->discount_approved_by ? (int) $deal->discount_approved_by : null,
            'discount_source' => $deal->discount_source,
        ];

        $baseAmount = $this->discountBaseAmount($deal);

        if ($discountPercentage === null || $discountPercentage <= 0) {
            if ($deal->discount_percentage === null && $deal->original_amount === null && $deal->discount_source === null) {
                return [
                    'applied' => false,
                    'before' => $before,
                    'after' => $before,
                ];
            }

            if ($deal->discount_source !== null && $deal->discount_source !== 'agent_manual') {
                return [
                    'applied' => false,
                    'before' => $before,
                    'after' => $before,
                ];
            }

            $deal->update([
                'amount' => round($baseAmount, 2),
                'original_amount' => null,
                'discount_percentage' => null,
                'discount_approved_by' => null,
                'discount_source' => null,
            ]);

            return [
                'applied' => false,
                'before' => $before,
                'after' => [
                    'amount' => (float) $deal->amount,
                    'original_amount' => null,
                    'discount_percentage' => null,
                    'discount_approved_by' => null,
                    'discount_source' => null,
                ],
            ];
        }

        $discountedAmount = $discountPayableAmount !== null
            ? round($discountPayableAmount, 2)
            : round($baseAmount * (1 - ($discountPercentage / 100)), 2);

        $deal->update([
            'amount' => $discountedAmount,
            'original_amount' => round($baseAmount, 2),
            'discount_percentage' => $this->normalizedDiscountPercentage($discountPercentage),
            'discount_approved_by' => $actorId,
            'discount_source' => 'agent_manual',
        ]);

        return [
            'applied' => true,
            'before' => $before,
            'after' => [
                'amount' => (float) $deal->amount,
                'original_amount' => $deal->original_amount !== null ? (float) $deal->original_amount : null,
                'discount_percentage' => $deal->discount_percentage !== null ? (float) $deal->discount_percentage : null,
                'discount_approved_by' => $deal->discount_approved_by ? (int) $deal->discount_approved_by : null,
                'discount_source' => $deal->discount_source,
            ],
        ];
    }

    private function recordDiscountAudit(Request $request, Deal $deal, array $beforeState, array $afterState, string $reason): void
    {
        $this->auditService->fromRequest(
            $request,
            (int) $deal->platform_id,
            CrmAuditAction::DEAL_DISCOUNT,
            'deal',
            (int) $deal->id,
            $beforeState,
            $afterState,
            $reason
        );
    }

}
