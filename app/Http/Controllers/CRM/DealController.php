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
use App\Services\SubscriptionProvisioningService;
use App\Services\WalletSettingsService;
use App\Support\CrmAuditAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DealController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly AuditService $auditService,
        private readonly \App\Services\RenewalService $renewalService,
        private readonly DealPaymentService $dealPaymentService,
        private readonly NotificationService $notificationService,
        private readonly SubscriptionProvisioningService $subscriptionProvisioningService,
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
            } else {
                $duration = $validated['duration'] ?? $deal->duration;
                $validated['amount'] = $this->dealPaymentService->resolveAmountForDuration($product, (string) $duration);
            }

            $validated['currency'] = $product->currency ?: ($deal->platform?->currency_code ?: $deal->currency ?: 'KES');
            unset($validated['product_price_id']);
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
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $client->platform_id,
            'You do not have access to this client market.'
        );

        $deal = $this->dealPaymentService->createPendingDealFromCatalog(
            $client,
            (int) $validated['product_id'],
            isset($validated['product_price_id']) ? (int) $validated['product_price_id'] : null,
            $validated['duration'] ?? null,
            (int) $request->user()->id,
            isset($validated['lead_id']) ? (int) $validated['lead_id'] : null
        );

        $deal->load(['client', 'product', 'platform']);
        return response()->json($deal, 201);
    }

    public function activate(Request $request, Deal $deal)
    {
        $this->authorizeDealAccess($request, $deal);
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
            'payment_method' => 'required|in:manual,stk,link,free_trial',
            'payment_reference' => 'required_if:payment_method,manual|nullable|string|max:255',
            'payment_link_provider' => 'nullable|string|max:120',
            'free_trial_pin' => ['required_if:payment_method,free_trial', 'nullable', 'regex:/^\d{4,6}$/'],
            'approved_by' => 'nullable|string|max:255',
            'duration_days' => 'nullable|integer|min:1|max:365',
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
        if ($freeTrialGuard = $this->freeTrialPermissionResponse($request, $paymentMethod, $validated['free_trial_pin'] ?? null)) {
            return $freeTrialGuard;
        }
        $paymentLinkProvider = $paymentMethod === 'link'
            ? $this->dealPaymentService->resolvePaymentLinkProvider($client, $validated['payment_link_provider'] ?? null)
            : null;

        // Resolve duration days: explicit request param → ProductPrice lookup → legacy enum fallback
        $explicitDays = isset($validated['duration_days']) ? (int) $validated['duration_days'] : 0;
        if ($explicitDays > 0) {
            $durationDays = $explicitDays;
        } else {
            // Try to resolve from the deal's product + duration via product_prices table
            $durationDays = $this->dealPaymentService->resolveDurationDaysFromCatalog($deal);
        }
        if ($durationDays < 1) {
            $durationDays = 30;
        }

        $beforeState = [
            'deal_status' => $deal->status,
            'client_profile_status' => $client->profile_status,
            'payment_id' => $deal->payment_id,
            'is_free_trial' => (bool) $deal->is_free_trial,
            'payment_method' => $paymentMethod,
        ];

        DB::beginTransaction();
        try {
            $platform = $client->platform ?? Platform::findOrFail($client->platform_id);
            $payment = null;

            if ($paymentMethod === 'manual') {
                $payment = $this->dealPaymentService->createManualPaymentForDeal(
                    $deal,
                    $client,
                    (string) $validated['payment_reference'],
                    (int) $request->user()->id
                );
            } elseif ($paymentMethod === 'link') {
                $result = $this->dealPaymentService->startLinkPaymentForDeal($deal, $client, $request, $paymentLinkProvider);
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
                $initiation = $this->dealPaymentService->initiatePaymentForDeal($deal, $client, $paymentMethod, $request, $paymentLinkProvider);
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
                    'payment_method' => $paymentMethod,
                    'payment_link_provider' => $paymentLinkProvider,
                ];

                $this->auditService->fromRequest(
                    $request,
                    (int) $client->platform_id,
                    CrmAuditAction::DEAL_ACTIVATE,
                    'deal',
                    (int) $deal->id,
                    $beforeState,
                    $afterState,
                    $validated['reason'] ?: 'Activation initiated pending payment'
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
                $validated['reason'] ?: 'Activated via CRM flow'
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
                    $validated['reason'] ?: 'Free trial activation from CRM flow'
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

        $request->validate([
            'reason' => 'required|string|max:500',
            'notify_client' => 'nullable|boolean',
            'notification_message' => 'nullable|string|max:500',
            'notification_template_id' => 'nullable|integer|exists:templates,id',
        ]);

        $beforeState = [
            'deal_status' => $deal->status,
            'client_profile_status' => $client->profile_status,
        ];

        DB::beginTransaction();
        try {
            $platform = $client->platform ?? Platform::findOrFail($client->platform_id);
            $wpSync = WpSyncService::forPlatform($client->platform_id);
            $wpSync->deactivateClient($client->wp_post_id);

            $deal->update(['status' => 'cancelled']);

            $syncService = new ClientSyncService($platform);
            $syncService->syncOne($client->wp_post_id);
            $client->refresh();

            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                CrmAuditAction::DEAL_DEACTIVATE,
                'deal',
                (int) $deal->id,
                $beforeState,
                [
                    'deal_status' => 'cancelled',
                    'client_profile_status' => $client->profile_status,
                ],
                (string) $request->reason
            );

            TimelineEvent::create([
                'platform_id' => $client->platform_id,
                'entity_type' => 'client',
                'entity_id' => $client->id,
                'event_type' => 'profile_deactivated',
                'actor_id' => $request->user()->id,
                'content' => [
                    'deal_id' => $deal->id,
                    'reason' => $request->reason,
                ],
                'created_at' => now(),
            ]);

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
            'payment_method' => 'required|in:manual,stk,link,free_trial',
            'payment_reference' => 'required_if:payment_method,manual|nullable|string|max:255',
            'payment_link_provider' => 'nullable|string|max:120',
            'free_trial_pin' => ['required_if:payment_method,free_trial', 'nullable', 'regex:/^\d{4,6}$/'],
            'approved_by' => 'nullable|string|max:255',
        ]);

        if ($missingColumnsResponse = $this->missingSprint6DealColumnsResponse()) {
            return $missingColumnsResponse;
        }

        $client = $deal->client;
        if (!$client) {
            return response()->json(['message' => 'Deal has no associated client'], 422);
        }

        $paymentMethod = (string) $validated['payment_method'];
        if ($freeTrialGuard = $this->freeTrialPermissionResponse($request, $paymentMethod, $validated['free_trial_pin'] ?? null)) {
            return $freeTrialGuard;
        }
        $paymentLinkProvider = $paymentMethod === 'link'
            ? $this->dealPaymentService->resolvePaymentLinkProvider($client, $validated['payment_link_provider'] ?? null)
            : null;

        $beforeState = [
            'expires_at' => $deal->expires_at?->toDateTimeString(),
            'payment_id' => $deal->payment_id,
            'payment_reference' => $deal->payment_reference,
            'payment_method' => $paymentMethod,
        ];

        DB::beginTransaction();
        try {
            $platform = $client->platform ?? Platform::findOrFail($client->platform_id);
            $wpSync = WpSyncService::forPlatform($client->platform_id);
            $wpSync->extendClient($client->wp_post_id, (int) $validated['additional_days']);

            $payment = null;
            if ($paymentMethod === 'manual') {
                $payment = $this->dealPaymentService->createManualPaymentForDeal(
                    $deal,
                    $client,
                    (string) $validated['payment_reference'],
                    (int) $request->user()->id
                );
            } elseif (in_array($paymentMethod, ['stk', 'link'], true)) {
                $initiation = $this->dealPaymentService->initiatePaymentForDeal($deal, $client, $paymentMethod, $request, $paymentLinkProvider);
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
                    'payment_method' => $paymentMethod,
                    'payment_link_provider' => $paymentLinkProvider,
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
            'payment_method' => 'required|in:manual,stk,link,free_trial',
            'payment_reference' => 'required_if:payment_method,manual|nullable|string|max:255',
            'payment_link_provider' => 'nullable|string|max:120',
            'free_trial_pin' => ['required_if:payment_method,free_trial', 'nullable', 'regex:/^\d{4,6}$/'],
            'approved_by' => 'nullable|string|max:255',
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
        if ($freeTrialGuard = $this->freeTrialPermissionResponse($request, $paymentMethod, $validated['free_trial_pin'] ?? null)) {
            return $freeTrialGuard;
        }
        $paymentLinkProvider = $paymentMethod === 'link'
            ? $this->dealPaymentService->resolvePaymentLinkProvider($client, $validated['payment_link_provider'] ?? null)
            : null;
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

            $newDeal = Deal::create([
                'platform_id' => $deal->platform_id,
                'client_id' => $deal->client_id,
                'lead_id' => $deal->lead_id,
                'product_id' => $deal->product_id,
                'plan_type' => $deal->plan_type,
                'amount' => $deal->amount,
                'currency' => $deal->currency,
                'duration' => $deal->duration,
                'status' => $activatesImmediately ? 'active' : 'awaiting_payment',
                'activated_at' => $activatesImmediately ? now() : null,
                'expires_at' => $activatesImmediately ? now()->addDays($additionalDays) : null,
                'assigned_to' => $deal->assigned_to,
                'is_free_trial' => $isFreeTrial,
                'free_trial_approved_by' => null,
                'payment_reference' => $paymentMethod === 'manual' ? (string) $validated['payment_reference'] : null,
            ]);

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
                $initiation = $this->dealPaymentService->initiatePaymentForDeal($newDeal, $client, $paymentMethod, $request, $paymentLinkProvider);
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
                    'payment_method' => $paymentMethod,
                    'payment_link_provider' => $paymentLinkProvider,
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

    private function missingSprint6DealColumnsResponse(): ?\Illuminate\Http\JsonResponse
    {
        static $missingColumns = null;
        if ($missingColumns === null) {
            $missingColumns = [];
            foreach (['is_free_trial', 'free_trial_approved_by', 'payment_reference'] as $column) {
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

    private function authorizeDealAccess(Request $request, Deal $deal): void
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $deal->platform_id,
            'You do not have access to this deal market.'
        );
    }

}
