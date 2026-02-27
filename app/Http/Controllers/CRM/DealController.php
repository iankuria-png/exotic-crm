<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Deal;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Template;
use App\Models\TimelineEvent;
use App\Models\Platform;
use App\Services\AuditService;
use App\Services\WpSyncService;
use App\Services\ClientSyncService;
use App\Services\MarketAuthorizationService;
use App\Services\NotificationService;
use App\Support\CrmAuditAction;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DealController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly AuditService $auditService,
        private readonly \App\Services\RenewalService $renewalService,
        private readonly NotificationService $notificationService
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

            $product = $this->resolveScopedProduct($productId, (int) $deal->platform_id);
            $validated['product_id'] = $product->id;
            $validated['plan_type'] = $this->derivePlanTypeFromProduct($product);

            $productPriceId = isset($validated['product_price_id']) ? (int) $validated['product_price_id'] : null;
            $priceRow = $productPriceId ? $this->resolveScopedProductPrice($productPriceId, $product) : null;

            if ($priceRow) {
                $validated['amount'] = (float) $priceRow->price;
                $validated['duration'] = $this->mapDurationKeyToLegacy($priceRow->duration_key);
            } else {
                $duration = $validated['duration'] ?? $deal->duration;
                $validated['amount'] = $this->resolveAmountForDuration($product, (string) $duration);
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

        $product = $this->resolveScopedProduct((int) $validated['product_id'], (int) $client->platform_id);
        $planType = $this->derivePlanTypeFromProduct($product);

        // Resolve amount and duration from product_price_id if provided, otherwise fall back to legacy
        $productPriceId = isset($validated['product_price_id']) ? (int) $validated['product_price_id'] : null;
        $priceRow = $productPriceId ? $this->resolveScopedProductPrice($productPriceId, $product) : null;

        if ($priceRow) {
            $amount = (float) $priceRow->price;
            $duration = $this->mapDurationKeyToLegacy($priceRow->duration_key);
            $durationDays = $priceRow->duration_days;
        } else {
            $duration = $validated['duration'] ?? 'monthly';
            $amount = $this->resolveAmountForDuration($product, $duration);
            $durationDays = null;
        }

        $deal = Deal::create([
            'platform_id' => $client->platform_id,
            'client_id' => $client->id,
            'lead_id' => $validated['lead_id'] ?? null,
            'product_id' => $product->id,
            'plan_type' => $planType,
            'amount' => $amount,
            'currency' => $product->currency ?: ($client->platform->currency_code ?? 'KES'),
            'duration' => $duration,
            'status' => 'pending',
            'assigned_to' => $request->user()->id,
        ]);

        TimelineEvent::create([
            'platform_id' => $client->platform_id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'deal_created',
            'actor_id' => $request->user()->id,
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
            'actor_id' => $request->user()->id,
            'content' => [
                'deal_id' => $deal->id,
                'plan_type' => $deal->plan_type,
                'amount' => $deal->amount,
            ],
            'created_at' => now(),
        ]);

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
            'approved_by' => 'required_if:payment_method,free_trial|nullable|string|max:255',
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
        if ($freeTrialGuard = $this->freeTrialPermissionResponse($request, $paymentMethod)) {
            return $freeTrialGuard;
        }

        // Prefer explicit duration_days from request (set by dynamic catalog flow),
        // otherwise fall back to legacy duration-based defaults
        $explicitDays = isset($validated['duration_days']) ? (int) $validated['duration_days'] : 0;
        if ($explicitDays > 0) {
            $durationDays = $explicitDays;
        } else {
            $durationDays = match ($deal->duration) {
                'weekly' => 7,
                'biweekly' => 14,
                'monthly' => 30,
                'manual' => 30,
                default => 30,
            };
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
                $payment = $this->createManualPaymentForDeal(
                    $deal,
                    $client,
                    (string) $validated['payment_reference'],
                    (int) $request->user()->id
                );
            } elseif (in_array($paymentMethod, ['stk', 'link'], true)) {
                $initiation = $this->initiatePaymentForDeal($deal, $client, $paymentMethod, $request);
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

            $wpSync = WpSyncService::forPlatform($client->platform_id);
            $wpSync->activateClient(
                $client->wp_post_id,
                $deal->plan_type,
                $durationDays,
                $deal->id
            );

            $isFreeTrial = $paymentMethod === 'free_trial';
            $deal->update([
                'status' => 'active',
                'activated_at' => now(),
                'expires_at' => now()->addDays($durationDays),
                'payment_id' => $payment?->id,
                'payment_reference' => $payment?->transaction_reference
                    ?? ($paymentMethod === 'manual' ? (string) $validated['payment_reference'] : null),
                'is_free_trial' => $isFreeTrial,
                'free_trial_approved_by' => $isFreeTrial ? (string) $validated['approved_by'] : null,
            ]);

            // Re-sync client from WP to get updated meta
            $syncService = new ClientSyncService($platform);
            $syncService->syncOne($client->wp_post_id);
            $client->refresh();

            $afterState = [
                'deal_status' => 'active',
                'client_profile_status' => $client->profile_status,
                'expires_at' => $deal->expires_at->toDateTimeString(),
                'payment_id' => $deal->payment_id,
                'payment_reference' => $deal->payment_reference,
                'payment_method' => $paymentMethod,
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
                        'approved_by' => (string) $validated['approved_by'],
                        'duration_days' => $durationDays,
                    ],
                    $validated['reason'] ?: 'Free trial activation from CRM flow'
                );
            }

            TimelineEvent::create([
                'platform_id' => $client->platform_id,
                'entity_type' => 'client',
                'entity_id' => $client->id,
                'event_type' => 'profile_activated',
                'actor_id' => $request->user()->id,
                'content' => [
                    'deal_id' => $deal->id,
                    'plan_type' => $deal->plan_type,
                    'duration_days' => $durationDays,
                    'expires_at' => $deal->expires_at->toDateTimeString(),
                    'payment_method' => $paymentMethod,
                ],
                'created_at' => now(),
            ]);

            TimelineEvent::create([
                'platform_id' => $client->platform_id,
                'entity_type' => 'deal',
                'entity_id' => $deal->id,
                'event_type' => 'deal_activated',
                'actor_id' => $request->user()->id,
                'content' => [
                    'duration_days' => $durationDays,
                    'expires_at' => $deal->expires_at->toDateTimeString(),
                    'payment_method' => $paymentMethod,
                ],
                'created_at' => now(),
            ]);

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
            'approved_by' => 'required_if:payment_method,free_trial|nullable|string|max:255',
        ]);

        if ($missingColumnsResponse = $this->missingSprint6DealColumnsResponse()) {
            return $missingColumnsResponse;
        }

        $client = $deal->client;
        if (!$client) {
            return response()->json(['message' => 'Deal has no associated client'], 422);
        }

        $paymentMethod = (string) $validated['payment_method'];
        if ($freeTrialGuard = $this->freeTrialPermissionResponse($request, $paymentMethod)) {
            return $freeTrialGuard;
        }

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
                $payment = $this->createManualPaymentForDeal(
                    $deal,
                    $client,
                    (string) $validated['payment_reference'],
                    (int) $request->user()->id
                );
            } elseif (in_array($paymentMethod, ['stk', 'link'], true)) {
                $initiation = $this->initiatePaymentForDeal($deal, $client, $paymentMethod, $request);
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
                'free_trial_approved_by' => $paymentMethod === 'free_trial'
                    ? (string) $validated['approved_by']
                    : $deal->free_trial_approved_by,
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
                        'approved_by' => (string) $validated['approved_by'],
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
            'approved_by' => 'required_if:payment_method,free_trial|nullable|string|max:255',
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
        if ($freeTrialGuard = $this->freeTrialPermissionResponse($request, $paymentMethod)) {
            return $freeTrialGuard;
        }
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
                'free_trial_approved_by' => $isFreeTrial ? (string) $validated['approved_by'] : null,
                'payment_reference' => $paymentMethod === 'manual' ? (string) $validated['payment_reference'] : null,
            ]);

            $payment = null;
            if ($paymentMethod === 'manual') {
                $payment = $this->createManualPaymentForDeal(
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
                $initiation = $this->initiatePaymentForDeal($newDeal, $client, $paymentMethod, $request);
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
                        'approved_by' => (string) $validated['approved_by'],
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

    private function createManualPaymentForDeal(Deal $deal, Client $client, string $paymentReference, int $actorId): Payment
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

    private function initiatePaymentForDeal(Deal $deal, Client $client, string $method, Request $request): array
    {
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
            ],
        ]);

        if ($method === 'stk') {
            $baseUrl = rtrim((string) config('services.django.base_url'), '/');
            if ($baseUrl === '') {
                $payment->update([
                    'status' => 'failed',
                    'raw_payload' => [
                        'source' => 'deal_payment_initiation',
                        'method' => 'stk',
                        'error' => 'Django base URL not configured',
                    ],
                ]);

                return [
                    'success' => false,
                    'message' => 'Payment service URL is not configured.',
                    'payment' => $payment,
                ];
            }

            $nameParts = preg_split('/\s+/', trim((string) $client->name), 2) ?: [];
            $payload = [
                'organization_code' => '76',
                'payment_id' => $payment->id,
                'product_id' => $payment->product_id,
                'platform_id' => $payment->platform_id,
                'user_id' => $payment->user_id,
                'phone' => $phone,
                'amount' => (float) $payment->amount,
                'duration' => $payment->duration ?: $deal->duration ?: 'monthly',
                'first_name' => $nameParts[0] ?? null,
                'last_name' => $nameParts[1] ?? null,
                'email' => $client->email,
            ];

            $response = Http::timeout(30)->post("{$baseUrl}/initiate/", $payload);
            if ($response->successful() && ($response->json('message') === 'Payment initiated')) {
                $payment->update([
                    'status' => 'initiated',
                    'raw_payload' => [
                        'source' => 'deal_payment_initiation',
                        'method' => 'stk',
                        'django_response' => $response->json(),
                    ],
                ]);

                return [
                    'success' => true,
                    'message' => 'STK push sent. Subscription will activate after payment confirmation.',
                    'payment' => $payment->fresh(['platform', 'product', 'client']),
                ];
            }

            $payment->update([
                'status' => 'failed',
                'raw_payload' => [
                    'source' => 'deal_payment_initiation',
                    'method' => 'stk',
                    'http_status' => $response->status(),
                    'response' => $response->json(),
                ],
            ]);

            return [
                'success' => false,
                'message' => $response->json('error')
                    ?? $response->json('message')
                    ?? 'STK push could not be initiated.',
                'payment' => $payment,
            ];
        }

        if ($method === 'link') {
            $paymentUrl = $this->buildPaymentLinkUrl($client->platform);
            if (!$paymentUrl) {
                $payment->update([
                    'status' => 'failed',
                    'raw_payload' => [
                        'source' => 'deal_payment_initiation',
                        'method' => 'link',
                        'error' => 'Payment link URL unavailable',
                    ],
                ]);

                return [
                    'success' => false,
                    'message' => 'Payment page URL could not be determined for this market.',
                    'payment' => $payment,
                ];
            }

            $message = sprintf(
                'Complete your payment of %s %s here: %s',
                $payment->currency ?: 'KES',
                number_format((float) $payment->amount),
                $paymentUrl
            );

            $smsResult = $this->notificationService->sendSms($phone, $message, [
                'purpose' => 'deal_activation_payment_link',
                'deal_id' => $deal->id,
                'payment_id' => $payment->id,
                'platform_id' => $deal->platform_id,
                'phone_prefix' => $client->platform?->phone_prefix ?: '254',
            ]);

            if (($smsResult['success'] ?? false) === true || ($smsResult['status'] ?? '') === 'disabled') {
                $payment->update([
                    'status' => 'initiated',
                    'raw_payload' => [
                        'source' => 'deal_payment_initiation',
                        'method' => 'link',
                        'payment_url' => $paymentUrl,
                        'sms_status' => $smsResult['status'] ?? null,
                    ],
                ]);

                return [
                    'success' => true,
                    'message' => ($smsResult['status'] ?? '') === 'disabled'
                        ? 'Payment link prepared (SMS disabled). Subscription will activate after payment confirmation.'
                        : 'Payment link sent by SMS. Subscription will activate after payment confirmation.',
                    'payment' => $payment->fresh(['platform', 'product', 'client']),
                ];
            }

            $payment->update([
                'status' => 'failed',
                'raw_payload' => [
                    'source' => 'deal_payment_initiation',
                    'method' => 'link',
                    'payment_url' => $paymentUrl,
                    'sms_result' => $smsResult,
                ],
            ]);

            return [
                'success' => false,
                'message' => $smsResult['provider_response'] ?? 'Payment link SMS could not be sent.',
                'payment' => $payment,
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

    private function buildPaymentLinkUrl(?Platform $platform, ?string $requestedProvider = null): ?string
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

    /**
     * @deprecated Kept for backwards compatibility while external clients move to payment_method contract.
     */
    private function resolveVerifiedPaymentForDeal(Deal $deal, $paymentId = null): ?Payment
    {
        $query = Payment::query()
            ->where('platform_id', $deal->platform_id)
            ->whereIn('status', ['completed', 'success']);

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

    private function freeTrialPermissionResponse(Request $request, string $paymentMethod): ?\Illuminate\Http\JsonResponse
    {
        if ($paymentMethod !== 'free_trial') {
            return null;
        }

        if ($this->marketAuthorizationService->isManager($request->user())) {
            return null;
        }

        return response()->json([
            'message' => 'Only admin or sub-admin users can approve free trial activations.',
        ], 403);
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

    private function resolveScopedProduct(int $productId, int $platformId): Product
    {
        $product = Product::query()->findOrFail($productId);
        if ((int) ($product->platform_id ?? 0) !== $platformId) {
            throw ValidationException::withMessages([
                'product_id' => 'Selected product does not belong to this market.',
            ]);
        }

        return $product;
    }

    private function derivePlanTypeFromProduct(Product $product): string
    {
        // Use tier field if set by dynamic catalog
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

    private function resolveAmountForDuration(Product $product, string $duration): float
    {
        return (float) match ($duration) {
            'weekly' => $product->weekly_price ?? 0,
            'biweekly' => $product->biweekly_price ?? 0,
            'monthly' => $product->monthly_price ?? 0,
            'manual' => 0,
            default => 0,
        };
    }

    private function resolveScopedProductPrice(int $productPriceId, Product $product): ProductPrice
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

    private function mapDurationKeyToLegacy(string $durationKey): string
    {
        return match ($durationKey) {
            '1_week' => 'weekly',
            '2_weeks' => 'biweekly',
            '1_month' => 'monthly',
            default => 'manual',
        };
    }
}
