<?php

namespace App\Services;

use App\Exceptions\ManualPaymentReferenceConflictException;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Deal;
use App\Models\ManualPaymentBundle;
use App\Models\Payment;
use App\Models\Product;
use App\Models\TimelineEvent;
use App\Support\CrmAuditAction;
use App\Support\DeactivationRequest;
use App\Support\DealDeactivationReason;
use App\Support\LinkedPaymentAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ManualPaymentBundleService
{
    public function __construct(
        private readonly DealPaymentService $dealPaymentService,
        private readonly WalletSettingsService $walletSettingsService,
        private readonly AuditService $auditService,
        private readonly SubscriptionDeactivationService $subscriptionDeactivationService,
        private readonly SubscriptionProvisioningService $subscriptionProvisioningService
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function preview(array $payload): array
    {
        $platformId = (int) ($payload['platform_id'] ?? 0);
        $referenceRoot = $this->normalizeReferenceRoot((string) ($payload['reference_root'] ?? ''));
        $this->ensureReferenceRootPresent($referenceRoot);
        $this->assertNoReferenceConflict($platformId, $referenceRoot);

        return $this->buildPreview($payload, $referenceRoot);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function commit(array $payload, int $actorId): array
    {
        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            throw ValidationException::withMessages([
                'idempotency_key' => 'An idempotency key is required.',
            ]);
        }

        $existingBundle = ManualPaymentBundle::query()
            ->with(['payments.client', 'payments.deal'])
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingBundle) {
            return $this->serializeCommittedBundle($existingBundle, true);
        }

        $referenceRoot = $this->normalizeReferenceRoot((string) ($payload['reference_root'] ?? ''));
        $this->ensureReferenceRootPresent($referenceRoot);
        $preview = $this->buildPreview($payload, $referenceRoot);
        $this->assertDiscountPermissions($preview, trim((string) ($payload['discount_pin'] ?? '')));

        $draft = DB::transaction(function () use ($preview, $actorId, $idempotencyKey) {
            $existingByKey = ManualPaymentBundle::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingByKey) {
                return [
                    'bundle' => $existingByKey->load(['payments.client', 'payments.deal']),
                    'existing' => true,
                    'items' => [],
                ];
            }

            $this->assertNoReferenceConflict(
                (int) $preview['platform_id'],
                (string) $preview['reference_root'],
                true
            );

            $bundle = ManualPaymentBundle::query()->create([
                'platform_id' => (int) $preview['platform_id'],
                'reference_root' => (string) $preview['reference_root'],
                'total_amount' => (float) $preview['total_amount'],
                'allocated_amount' => (float) $preview['allocated_total'],
                'unallocated_amount' => (float) $preview['unallocated_amount'],
                'currency' => (string) $preview['currency'],
                'reason' => $preview['reason'] ?: null,
                'status' => ManualPaymentBundle::STATUS_COMMITTING,
                'audit_state' => (float) $preview['unallocated_amount'] > 0
                    ? ManualPaymentBundle::AUDIT_NEEDS_FINANCE_RESOLUTION
                    : ManualPaymentBundle::AUDIT_RESOLVED,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actorId,
            ]);

            $draftItems = [];

            foreach ($preview['items'] as $item) {
                $client = Client::query()
                    ->with('platform')
                    ->findOrFail((int) $item['client_id']);

                $targetDeal = $this->dealPaymentService->createPendingDealFromCatalog(
                    $client,
                    (int) $item['product_id'],
                    $item['product_price_id'] ? (int) $item['product_price_id'] : null,
                    $item['duration'] ?? null,
                    $actorId,
                    null
                );

                // Stamp origin and child reference on the newly created pending deal
                $targetDeal->forceFill([
                    'origin' => 'manual_payment_bundle',
                    'payment_reference' => (string) $item['child_reference'],
                    'amount' => (float) $item['allocated_amount'],
                    'discount_percentage' => (float) $item['discount_percentage'] > 0 ? (float) $item['discount_percentage'] : null,
                    'original_amount' => (float) $item['discount_percentage'] > 0 ? (float) $item['base_amount'] : null,
                    'discount_approved_by' => (float) $item['discount_percentage'] > 0 ? $actorId : null,
                    'discount_source' => (float) $item['discount_percentage'] > 0 ? 'agent_manual' : null,
                ])->save();

                $payment = $this->dealPaymentService->createManualPaymentForDeal(
                    $targetDeal,
                    $client,
                    (string) $item['child_reference'],
                    $actorId,
                    [
                        'manual_payment_bundle_id' => (int) $bundle->id,
                        'amount' => (float) $item['allocated_amount'],
                        'currency' => (string) $preview['currency'],
                        'reference_number' => (string) $item['child_reference'],
                        'reference_root' => (string) $preview['reference_root'],
                        'reference_sequence' => (int) $item['reference_sequence'],
                        'status' => 'pending',
                        'reconciliation_state' => 'open',
                        'raw_payload' => [
                            'source' => 'manual_payment_bundle',
                            'bundle_id' => (int) $bundle->id,
                            'target_action' => 'new_deal',
                        ],
                    ]
                );

                $draftItems[] = [
                    'source_deal_id' => 0,
                    'target_deal_id' => (int) $targetDeal->id,
                    'new_deal_created' => true,
                    'payment_id' => (int) $payment->id,
                    'client_id' => (int) $client->id,
                    'client_wp_post_id' => (int) ($client->wp_post_id ?? 0),
                    'action' => 'new_deal',
                    'duration_days' => (int) $item['duration_days'],
                    'allocated_amount' => (float) $item['allocated_amount'],
                    'base_amount' => (float) $item['base_amount'],
                    'discount_percentage' => (float) $item['discount_percentage'],
                    'child_reference' => (string) $item['child_reference'],
                ];
            }

            return [
                'bundle' => $bundle,
                'existing' => false,
                'items' => $draftItems,
                'preview' => $preview,
            ];
        });

        if (($draft['existing'] ?? false) === true) {
            return $this->serializeCommittedBundle($draft['bundle'], true);
        }

        $bundle = $draft['bundle'];
        $draftItems = $draft['items'];
        $preview = $draft['preview'];
        $activationFailures = [];
        $successfulActivations = [];
        foreach ($draftItems as $item) {
            try {
                $deal = Deal::query()->findOrFail((int) $item['target_deal_id']);
                $payment = Payment::query()->findOrFail((int) $item['payment_id']);

                $this->subscriptionProvisioningService->activateDeal($deal, [
                    'payment' => $payment,
                    'payment_method' => 'manual',
                    'duration_days' => (int) $item['duration_days'],
                    'payment_reference' => (string) $item['child_reference'],
                    'is_free_trial' => false,
                    'actor_id' => $actorId,
                    'payment_status' => 'completed',
                    'match_confidence' => 'manual',
                    'confirmed_by' => $actorId,
                    'confirmed_at' => now(),
                    'reconciliation_state' => 'resolved',
                    'sync_client' => false,
                    'emit_profile_activated_timeline' => false,
                    'emit_deal_activated_timeline' => false,
                    'timeline_context' => [
                        'manual_payment_bundle_id' => (int) $bundle->id,
                        'reference_root' => (string) $bundle->reference_root,
                    ],
                ]);

                $successfulActivations[] = $item;
            } catch (\Throwable $exception) {
                $activationFailures[] = [
                    'item' => $item,
                    'message' => $exception->getMessage(),
                ];
                break;
            }
        }

        if ($activationFailures === []) {
            return $this->finalizeSuccessfulCommit($bundle, $draftItems, $preview, $actorId);
        }

        return $this->handleCommitFailure(
            $bundle,
            $draftItems,
            $successfulActivations,
            $activationFailures,
            $actorId
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findReferenceConflict(int $platformId, string $referenceRoot): ?array
    {
        $normalizedRoot = $this->normalizeReferenceRoot($referenceRoot);
        if ($platformId <= 0 || $normalizedRoot === '') {
            return null;
        }

        $bundle = ManualPaymentBundle::query()
            ->where('platform_id', $platformId)
            ->where('reference_root', $normalizedRoot)
            ->first();

        if ($bundle) {
            return [
                'reference_root' => $normalizedRoot,
                'existing_bundle_id' => (int) $bundle->id,
                'existing_payment_id' => null,
                'status' => (string) $bundle->status,
            ];
        }

        $paymentConflict = Payment::query()
            ->where('platform_id', $platformId)
            ->where(function ($query) use ($normalizedRoot) {
                $query->where('reference_root', $normalizedRoot)
                    ->orWhere('transaction_reference', $normalizedRoot)
                    ->orWhere('reference_number', $normalizedRoot)
                    ->orWhere('transaction_reference', 'like', $normalizedRoot . '-%')
                    ->orWhere('reference_number', 'like', $normalizedRoot . '-%');
            })
            ->latest('id')
            ->first();

        if (!$paymentConflict) {
            return null;
        }

        return [
            'reference_root' => $normalizedRoot,
            'existing_bundle_id' => $paymentConflict->manual_payment_bundle_id ? (int) $paymentConflict->manual_payment_bundle_id : null,
            'existing_payment_id' => (int) $paymentConflict->id,
            'status' => $paymentConflict->manual_payment_bundle?->status ?? (string) $paymentConflict->status,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function sweepStuckBundles(int $olderThanMinutes = 10): array
    {
        $threshold = now()->subMinutes(max(1, $olderThanMinutes));
        $bundles = ManualPaymentBundle::query()
            ->with(['payments.client'])
            ->where('status', ManualPaymentBundle::STATUS_COMMITTING)
            ->where('created_at', '<=', $threshold)
            ->get();

        $swept = 0;

        foreach ($bundles as $bundle) {
            try {
                $this->markBundleAsCompensationFailed(
                    $bundle,
                    $bundle->payments->map(function (Payment $payment) {
                        return [
                            'payment_id' => (int) $payment->id,
                            'target_deal_id' => (int) $payment->deal_id,
                            'client_id' => (int) $payment->client_id,
                            'child_reference' => (string) ($payment->transaction_reference ?: $payment->reference_number),
                        ];
                    })->all(),
                    'Bundle was still committing after the sweeper threshold elapsed.',
                    null
                );
                $swept++;
            } catch (\Throwable $exception) {
                Log::warning('Stuck bundle sweep failed', [
                    'bundle_id' => $bundle->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'found' => $bundles->count(),
            'swept' => $swept,
        ];
    }

    /**
     * Check bundle children for material divergence that would prevent a clean void.
     *
     * @return list<array{deal_id: int, reason: string}>
     */
    public function detectDivergence(ManualPaymentBundle $bundle): array
    {
        $bundle->loadMissing(['payments']);
        $divergent = [];

        foreach ($bundle->payments as $payment) {
            $dealId = (int) $payment->deal_id;
            if ($dealId <= 0) {
                continue;
            }

            $deal = Deal::query()->find($dealId);
            if (!$deal) {
                $divergent[] = [
                    'deal_id' => $dealId,
                    'reason' => 'Deal no longer exists.',
                ];
                continue;
            }

            // Renewed: a newer deal references this deal's client + was created after the bundle
            $renewed = Deal::query()
                ->where('client_id', (int) $deal->client_id)
                ->where('origin', 'manual_payment_bundle')
                ->where('id', '>', $deal->id)
                ->where('created_at', '>', $bundle->created_at)
                ->exists();

            if (!$renewed) {
                $renewed = Deal::query()
                    ->where('client_id', (int) $deal->client_id)
                    ->where('status', 'active')
                    ->where('id', '!=', $deal->id)
                    ->where('activated_at', '>', $bundle->created_at)
                    ->exists();
            }

            if ($renewed) {
                $divergent[] = [
                    'deal_id' => $dealId,
                    'reason' => 'Deal has been renewed since bundle creation.',
                ];
                continue;
            }

            // Extended: expires_at moved forward after bundle creation
            if ($deal->expires_at && $deal->activated_at && $bundle->created_at) {
                $originalExpiry = Carbon::parse($deal->activated_at)->addDays(
                    $this->resolveOriginalDurationDays($payment)
                );
                $currentExpiry = Carbon::parse($deal->expires_at);
                if ($currentExpiry->gt($originalExpiry->addMinutes(5))) {
                    $divergent[] = [
                        'deal_id' => $dealId,
                        'reason' => 'Deal has been extended since bundle creation.',
                    ];
                    continue;
                }
            }

            // Already independently deactivated for a different reason
            if ($deal->status === 'cancelled') {
                $cancelledByBundle = $deal->cancelled_payment_id
                    && $bundle->payments->contains('id', $deal->cancelled_payment_id);
                if (!$cancelledByBundle) {
                    $divergent[] = [
                        'deal_id' => $dealId,
                        'reason' => "Deal was already deactivated (reason: {$deal->cancellation_reason_code}).",
                    ];
                    continue;
                }
            }

            // Re-linked to a different payment
            if ($deal->payment_id && (int) $deal->payment_id !== (int) $payment->id) {
                $divergent[] = [
                    'deal_id' => $dealId,
                    'reason' => 'Deal is now linked to a different payment.',
                ];
            }
        }

        return $divergent;
    }

    /**
     * Void a committed bundle: deactivate all child deals + mark child payments reversed/invalid.
     *
     * @param  array{reason_code: string, notes: string}  $params
     * @return array<string, mixed>
     */
    public function approveBundle(ManualPaymentBundle $bundle, int $actorId): array
    {
        $bundle->loadMissing(['payments.client', 'payments.deal', 'createdBy', 'platform']);

        if ($bundle->status !== ManualPaymentBundle::STATUS_COMMITTED) {
            throw ValidationException::withMessages([
                'bundle' => "Bundle cannot be approved from status '{$bundle->status}'.",
            ]);
        }

        if ($bundle->audit_state === ManualPaymentBundle::AUDIT_RESOLVED) {
            return $this->serializeCommittedBundle($bundle, true);
        }

        if ($bundle->audit_state !== ManualPaymentBundle::AUDIT_PENDING_FINANCE_REVIEW) {
            throw ValidationException::withMessages([
                'audit_state' => "Bundle in audit state '{$bundle->audit_state}' cannot be approved.",
            ]);
        }

        DB::transaction(function () use ($bundle, $actorId) {
            foreach ($bundle->payments as $payment) {
                if ($payment->reconciliation_state === 'manual_review') {
                    $payment->forceFill([
                        'reconciliation_state' => 'resolved',
                        'confirmed_by' => $payment->confirmed_by ?? $actorId,
                        'confirmed_at' => $payment->confirmed_at ?? now(),
                    ])->save();
                }
            }

            $bundle->forceFill([
                'audit_state' => ManualPaymentBundle::AUDIT_RESOLVED,
            ])->save();
        });

        return $this->serializeCommittedBundle($bundle->fresh(['payments.client', 'payments.deal', 'createdBy', 'platform']), false);
    }

    public function voidBundle(ManualPaymentBundle $bundle, array $params, int $actorId): array
    {
        $bundle->loadMissing(['payments.deal.client.platform', 'payments.client']);

        $allowedStatuses = [
            ManualPaymentBundle::STATUS_COMMITTED,
            ManualPaymentBundle::STATUS_COMPENSATION_FAILED,
        ];

        if (!in_array($bundle->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'bundle' => "Bundle cannot be voided from status '{$bundle->status}'.",
            ]);
        }

        $reasonCode = DealDeactivationReason::tryFrom((string) ($params['reason_code'] ?? ''));
        if (!$reasonCode) {
            throw ValidationException::withMessages([
                'reason_code' => 'A valid reason code is required.',
            ]);
        }

        $notes = trim((string) ($params['notes'] ?? ''));

        $divergent = $this->detectDivergence($bundle);
        if ($divergent !== []) {
            throw ValidationException::withMessages([
                'divergence' => $divergent,
            ]);
        }

        $deactivationRequest = new DeactivationRequest(
            reasonCode: $reasonCode,
            reasonNotes: $notes ?: "Bundle void: {$bundle->reference_root}",
            linkedPaymentAction: $this->resolveVoidPaymentAction($reasonCode)
        );

        $beforeState = [
            'status' => $bundle->status,
            'audit_state' => $bundle->audit_state,
        ];

        DB::transaction(function () use ($bundle, $deactivationRequest, $actorId) {
            foreach ($bundle->payments as $payment) {
                $deal = $payment->deal;

                if ($deal && in_array($deal->status, ['active', 'pending', 'awaiting_payment', 'paid'], true)) {
                    $this->subscriptionDeactivationService->deactivateDeal(
                        $deal,
                        $deactivationRequest,
                        $actorId
                    );
                } elseif ($deal && $deal->status !== 'cancelled') {
                    // Deal in non-active state (expired, etc.) — mark cancelled directly
                    $deal->forceFill([
                        'status' => 'cancelled',
                        'cancellation_reason_code' => $deactivationRequest->reasonCode->value,
                        'cancellation_notes' => $deactivationRequest->reasonNotes,
                        'cancelled_payment_id' => (int) $payment->id,
                    ])->save();
                }

                // Ensure payment resolution is applied even if deal was already cancelled
                $this->applyVoidPaymentResolution($payment, $deactivationRequest, $actorId);
            }

            $bundle->forceFill([
                'status' => ManualPaymentBundle::STATUS_VOIDED,
                'audit_state' => ManualPaymentBundle::AUDIT_VOIDED,
            ])->save();

            // Apply risk flags to all unique clients if reason warrants it
            if ($deactivationRequest->shouldFlagClientHighRisk()) {
                $this->applyBundleWideRiskFlags($bundle, $deactivationRequest, $actorId);
            }

            // Write a timeline event on each child payment to record the void
            foreach ($bundle->payments as $voidedPayment) {
                TimelineEvent::create([
                    'platform_id' => (int) $bundle->platform_id,
                    'entity_type' => 'payment',
                    'entity_id' => (int) $voidedPayment->id,
                    'event_type' => 'manual_payment_bundle_voided',
                    'actor_id' => $actorId,
                    'content' => [
                        'bundle_id' => (int) $bundle->id,
                        'reference_root' => $bundle->reference_root,
                        'reason_code' => $deactivationRequest->reasonCode->value,
                        'reason_notes' => $deactivationRequest->reasonNotes,
                        'payment_count' => $bundle->payments->count(),
                    ],
                    'created_at' => now(),
                ]);
            }
        });

        $this->auditService->record([
            'platform_id' => (int) $bundle->platform_id,
            'actor_id' => $actorId,
            'action' => CrmAuditAction::MANUAL_PAYMENT_BUNDLE_VOID,
            'entity_type' => 'manual_payment_bundle',
            'entity_id' => (int) $bundle->id,
            'before_state' => $beforeState,
            'after_state' => [
                'status' => ManualPaymentBundle::STATUS_VOIDED,
                'audit_state' => ManualPaymentBundle::AUDIT_VOIDED,
                'reason_code' => $deactivationRequest->reasonCode->value,
                'reason_notes' => $deactivationRequest->reasonNotes,
            ],
            'reason' => $deactivationRequest->auditReason(),
        ]);

        return $this->serializeCommittedBundle(
            $bundle->fresh(['payments.client', 'payments.deal']),
            false
        );
    }

    private function resolveVoidPaymentAction(DealDeactivationReason $reason): LinkedPaymentAction
    {
        return match ($reason) {
            DealDeactivationReason::PAYMENT_REVERSED => LinkedPaymentAction::REVERSE,
            DealDeactivationReason::INVALID_REFERENCE => LinkedPaymentAction::INVALIDATE,
            default => LinkedPaymentAction::REVERSE,
        };
    }

    private function applyVoidPaymentResolution(Payment $payment, DeactivationRequest $request, ?int $actorId): void
    {
        $action = $request->resolvedLinkedPaymentAction();

        if ($action === LinkedPaymentAction::NONE) {
            return;
        }

        // Skip if already resolved
        if ($payment->resolution_code !== null) {
            return;
        }

        $meta = [
            'bundle_void' => true,
            'reason_code' => $request->reasonCode->value,
            'reason_notes' => $request->reasonNotes,
            'actor_id' => $actorId,
            'applied_at' => now()->toDateTimeString(),
            'previous_status' => (string) $payment->status,
        ];

        if ($action === LinkedPaymentAction::REVERSE) {
            $payment->forceFill([
                'resolution_code' => Payment::RESOLUTION_REVERSED,
                'resolution_meta_json' => $meta,
            ])->save();
        } elseif ($action === LinkedPaymentAction::INVALIDATE) {
            $payment->forceFill([
                'status' => 'failed',
                'resolution_code' => Payment::RESOLUTION_INVALID_REFERENCE,
                'resolution_meta_json' => $meta,
                'failure_reason' => $request->reasonNotes ?: 'Invalid reference (bundle void).',
            ])->save();
        }
    }

    private function applyBundleWideRiskFlags(ManualPaymentBundle $bundle, DeactivationRequest $request, int $actorId): void
    {
        $clientIds = $bundle->payments
            ->pluck('client_id')
            ->filter(fn ($id) => (int) $id > 0)
            ->unique()
            ->values();

        foreach ($clientIds as $clientId) {
            $client = \App\Models\Client::query()->find((int) $clientId);
            if (!$client) {
                continue;
            }

            if ((bool) $client->is_high_risk) {
                TimelineEvent::create([
                    'platform_id' => (int) $client->platform_id,
                    'entity_type' => 'client',
                    'entity_id' => (int) $client->id,
                    'event_type' => 'client_risk_reaffirmed',
                    'actor_id' => $actorId,
                    'content' => [
                        'bundle_id' => (int) $bundle->id,
                        'reason_code' => $request->reasonCode->value,
                        'original_risk_reason_code' => $client->risk_reason_code,
                        'original_risk_marked_at' => optional($client->risk_marked_at)->toDateTimeString(),
                    ],
                    'created_at' => now(),
                ]);
                continue;
            }

            $client->forceFill([
                'is_high_risk' => true,
                'risk_reason_code' => $request->reasonCode->value,
                'risk_marked_at' => now(),
                'risk_marked_by' => $actorId,
            ])->save();

            TimelineEvent::create([
                'platform_id' => (int) $client->platform_id,
                'entity_type' => 'client',
                'entity_id' => (int) $client->id,
                'event_type' => 'client_risk_marked',
                'actor_id' => $actorId,
                'content' => [
                    'bundle_id' => (int) $bundle->id,
                    'reason_code' => $request->reasonCode->value,
                    'reason_notes' => $request->reasonNotes,
                ],
                'created_at' => now(),
            ]);
        }
    }

    private function resolveOriginalDurationDays(Payment $payment): int
    {
        $durationDays = (int) data_get($payment->payment_data, 'duration_days', 0);
        if ($durationDays > 0) {
            return $durationDays;
        }

        $rawPayload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $durationDays = (int) data_get($rawPayload, 'duration_days', 0);

        return $durationDays > 0 ? $durationDays : 30;
    }

    public function normalizeReferenceRoot(string $reference): string
    {
        return $this->dealPaymentService->normalizeReferenceRoot($reference);
    }

    private function ensureReferenceRootPresent(string $referenceRoot): void
    {
        if ($referenceRoot !== '') {
            return;
        }

        throw ValidationException::withMessages([
            'reference_root' => 'Reference root is required.',
        ]);
    }

    private function assertNoReferenceConflict(int $platformId, string $referenceRoot, bool $lock = false): void
    {
        $bundleQuery = ManualPaymentBundle::query()
            ->where('platform_id', $platformId)
            ->where('reference_root', $referenceRoot);

        if ($lock) {
            $bundleQuery->lockForUpdate();
        }

        $bundle = $bundleQuery->first();
        if ($bundle) {
            throw new ManualPaymentReferenceConflictException([
                'reference_root' => $referenceRoot,
                'existing_bundle_id' => (int) $bundle->id,
                'existing_payment_id' => null,
                'status' => (string) $bundle->status,
            ]);
        }

        $paymentQuery = Payment::query()
            ->where('platform_id', $platformId)
            ->where(function ($query) use ($referenceRoot) {
                $query->where('reference_root', $referenceRoot)
                    ->orWhere('transaction_reference', $referenceRoot)
                    ->orWhere('reference_number', $referenceRoot)
                    ->orWhere('transaction_reference', 'like', $referenceRoot . '-%')
                    ->orWhere('reference_number', 'like', $referenceRoot . '-%');
            });

        if ($lock) {
            $paymentQuery->lockForUpdate();
        }

        $payment = $paymentQuery->latest('id')->first();
        if ($payment) {
            throw new ManualPaymentReferenceConflictException([
                'reference_root' => $referenceRoot,
                'existing_bundle_id' => $payment->manual_payment_bundle_id ? (int) $payment->manual_payment_bundle_id : null,
                'existing_payment_id' => (int) $payment->id,
                'status' => $payment->manualPaymentBundle?->status ?? (string) $payment->status,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildPreview(array $payload, string $referenceRoot): array
    {
        $platformId = (int) ($payload['platform_id'] ?? 0);
        if ($platformId <= 0) {
            throw ValidationException::withMessages([
                'platform_id' => 'A valid platform is required.',
            ]);
        }

        $items = collect($payload['items'] ?? [])->values();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'At least one subscription row is required.',
            ]);
        }

        $clientIds = $items
            ->pluck('client_id')
            ->filter(fn ($value) => (int) $value > 0)
            ->map(fn ($value) => (int) $value)
            ->values();

        $productIds = $items
            ->pluck('product_id')
            ->filter(fn ($value) => (int) $value > 0)
            ->map(fn ($value) => (int) $value)
            ->values();

        if ($clientIds->count() !== $items->count() || $clientIds->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Each bundle item must reference a unique client.',
            ]);
        }

        $clients = Client::query()
            ->with(['platform'])
            ->whereIn('id', $clientIds->all())
            ->get()
            ->keyBy('id');

        $products = Product::query()
            ->with(['activePrices'])
            ->whereIn('id', $productIds->all())
            ->get()
            ->keyBy('id');

        if ($clients->count() !== $clientIds->count()) {
            throw ValidationException::withMessages([
                'items' => 'One or more selected clients could not be found.',
            ]);
        }

        $configuredCurrency = strtoupper(trim((string) ($payload['currency'] ?? '')));
        $reason = trim((string) ($payload['reason'] ?? ''));
        $totalAmount = round((float) ($payload['total_amount'] ?? 0), 2);
        if ($totalAmount <= 0) {
            throw ValidationException::withMessages([
                'total_amount' => 'A positive paid total is required.',
            ]);
        }

        $previewItems = [];
        $resolvedCurrency = null;

        foreach ($items as $index => $item) {
            $client = $clients->get((int) $item['client_id']);
            $product = $products->get((int) $item['product_id']);

            if (!$client) {
                throw ValidationException::withMessages([
                    'items' => 'Each bundle item must belong to a valid client.',
                ]);
            }

            if ((int) $client->platform_id !== $platformId) {
                throw ValidationException::withMessages([
                    'items' => 'All selected clients must belong to the same market.',
                ]);
            }

            if ((int) ($client->wp_post_id ?? 0) <= 0) {
                throw ValidationException::withMessages([
                    'items' => "Client {$client->id} is not linked to WordPress yet.",
                ]);
            }

            if (!$product) {
                throw ValidationException::withMessages([
                    'items' => 'Each bundle item must specify a valid product/plan.',
                ]);
            }

            if ((int) $product->platform_id !== $platformId) {
                throw ValidationException::withMessages([
                    'items' => 'All selected products must belong to the same market.',
                ]);
            }

            $duration = (string) ($item['duration'] ?? 'monthly');
            $productPriceId = isset($item['product_price_id']) && (int) $item['product_price_id'] > 0
                ? (int) $item['product_price_id']
                : null;

            // Resolve product price row (modern path first, then by duration_key, then legacy)
            $productPrice = null;
            if ($productPriceId) {
                $productPrice = $product->activePrices->firstWhere('id', $productPriceId);
            }

            if (!$productPrice) {
                $productPrice = $product->activePrices->first(function ($activePrice) use ($duration, $configuredCurrency) {
                    if ((string) $activePrice->duration_key !== $duration) {
                        return false;
                    }

                    if ($configuredCurrency === '') {
                        return true;
                    }

                    return strtoupper((string) $activePrice->currency) === $configuredCurrency;
                });
            }

            if ($productPrice) {
                $baseAmount = round((float) $productPrice->price, 2);
                $durationDays = (int) $productPrice->duration_days;
                $resolvedDuration = $this->dealPaymentService->mapDurationKeyToLegacy($productPrice->duration_key);
            } else {
                // Legacy fallback: use product price columns
                $resolvedDuration = $duration;
                $baseAmount = round((float) $this->dealPaymentService->resolveAmountForDuration($product, $resolvedDuration), 2);
                $durationDays = match ($resolvedDuration) {
                    'weekly' => 7,
                    'biweekly' => 14,
                    'quarterly' => 90,
                    'annually' => 365,
                    default => 30,
                };
            }

            $allocatedAmount = array_key_exists('allocated_amount', $item)
                ? round((float) $item['allocated_amount'], 2)
                : $baseAmount;

            if ($allocatedAmount <= 0) {
                throw ValidationException::withMessages([
                    'items' => "Allocated amount for client #{$client->id} must be greater than zero.",
                ]);
            }

            $discountPercentage = 0.0;
            if ($baseAmount > 0 && $allocatedAmount < $baseAmount) {
                $discountPercentage = round((($baseAmount - $allocatedAmount) / $baseAmount) * 100, 2);
            }

            $planType = $this->dealPaymentService->derivePlanTypeFromProduct($product);

            if ($resolvedCurrency === null) {
                $resolvedCurrency = $productPrice
                    ? strtoupper((string) $productPrice->currency)
                    : ($product->currency ?: ($client->platform?->currency_code ?? 'KES'));
            }

            $previewItems[] = [
                'reference_sequence' => $index + 1,
                'client_id' => (int) $client->id,
                'client_name' => (string) ($client->name ?: 'Unknown'),
                'product_id' => (int) $product->id,
                'product_name' => (string) ($product->display_name ?: $product->name),
                'product_price_id' => $productPrice ? (int) $productPrice->id : null,
                'plan_type' => $planType,
                'duration' => $resolvedDuration,
                'duration_days' => $durationDays,
                'base_amount' => $baseAmount,
                'allocated_amount' => $allocatedAmount,
                'discount_percentage' => $discountPercentage,
                'child_reference' => sprintf('%s-%d', $referenceRoot, $index + 1),
            ];
        }

        $allocatedTotal = round(collect($previewItems)->sum('allocated_amount'), 2);
        $shortfallAmount = round(max(0, $allocatedTotal - $totalAmount), 2);
        $unallocatedAmount = round(max(0, $totalAmount - $allocatedTotal), 2);

        $currency = $configuredCurrency !== ''
            ? $configuredCurrency
            : (string) ($resolvedCurrency ?? 'KES');

        $this->assertDiscountCaps($platformId, $previewItems);

        return [
            'platform_id' => $platformId,
            'reference_root' => $referenceRoot,
            'total_amount' => $totalAmount,
            'allocated_total' => $allocatedTotal,
            'shortfall_amount' => $shortfallAmount,
            'unallocated_amount' => $unallocatedAmount,
            'currency' => $currency,
            'reason' => $reason,
            'requires_discount_pin' => $shortfallAmount > 0,
            'items' => $previewItems,
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    private function assertDiscountPermissions(array $preview, string $discountPin): void
    {
        if (!($preview['requires_discount_pin'] ?? false)) {
            return;
        }

        if (!$this->walletSettingsService->discountPinIsConfigured()) {
            throw ValidationException::withMessages([
                'discount_pin' => 'Discount PIN is not configured. Ask an admin to set it in Settings first.',
            ]);
        }

        if ($discountPin === '' || !$this->walletSettingsService->verifyDiscountPin($discountPin)) {
            throw ValidationException::withMessages([
                'discount_pin' => 'Discount PIN is invalid.',
            ]);
        }
    }

    /**
     * @param  int  $platformId
     * @param  list<array<string, mixed>>  $previewItems
     */
    private function assertDiscountCaps(int $platformId, array $previewItems): void
    {
        $maxByPlatform = (array) data_get($this->walletSettingsService->getDiscountConfig(), 'max_percentage_by_platform', []);
        $maxPercentage = isset($maxByPlatform[(string) $platformId])
            ? (float) $maxByPlatform[(string) $platformId]
            : 0.0;

        foreach ($previewItems as $item) {
            if ((float) ($item['discount_percentage'] ?? 0) <= $maxPercentage) {
                continue;
            }

            throw ValidationException::withMessages([
                'items' => "Discount exceeds the configured market maximum of {$maxPercentage}%.",
            ]);
        }
    }

    /**
     * @param  ManualPaymentBundle  $bundle
     * @param  list<array<string, mixed>>  $draftItems
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function finalizeSuccessfulCommit(
        ManualPaymentBundle $bundle,
        array $draftItems,
        array $preview,
        int $actorId
    ): array {
        DB::transaction(function () use ($bundle, $draftItems, $preview, $actorId) {
            $now = now();

            foreach ($draftItems as $item) {
                $targetDeal = Deal::query()->findOrFail((int) $item['target_deal_id']);
                $sourceDeal = Deal::query()->find((int) $item['source_deal_id']);
                $payment = Payment::query()->findOrFail((int) $item['payment_id']);
                $expiresAt = $now->copy()->addDays((int) $item['duration_days']);
                $allocatedAmount = round((float) $item['allocated_amount'], 2);
                $discountPercentage = round((float) $item['discount_percentage'], 2);
                $baseAmount = round((float) $item['base_amount'], 2);

                $targetDeal->forceFill([
                    'status' => 'active',
                    'activated_at' => $now,
                    'expires_at' => $expiresAt,
                    'payment_id' => (int) $payment->id,
                    'payment_reference' => (string) $item['child_reference'],
                    'amount' => $allocatedAmount,
                    'original_amount' => $discountPercentage > 0 ? $baseAmount : null,
                    'discount_percentage' => $discountPercentage > 0 ? $discountPercentage : null,
                    'discount_approved_by' => $discountPercentage > 0 ? $actorId : null,
                    'discount_source' => $discountPercentage > 0 ? 'agent_manual' : null,
                    'is_free_trial' => false,
                    'free_trial_approved_by' => null,
                ])->save();

                $payment->forceFill([
                    'status' => 'completed',
                    'completed_at' => $payment->completed_at ?? $now,
                    'reconciliation_state' => 'resolved',
                    'match_confidence' => 'manual',
                    'confirmed_by' => $actorId,
                    'confirmed_at' => $payment->confirmed_at ?? $now,
                    'start_date' => $now,
                    'end_date' => $expiresAt,
                ])->save();

                if ($targetDeal->client_id) {
                    Deal::query()
                        ->whereKey((int) $targetDeal->id)
                        ->update(['payment_id' => (int) $payment->id]);
                }

                TimelineEvent::query()->create([
                    'platform_id' => (int) $targetDeal->platform_id,
                    'entity_type' => 'deal',
                    'entity_id' => (int) $targetDeal->id,
                    'event_type' => 'deal_activated',
                    'actor_id' => $actorId,
                    'content' => [
                        'payment_id' => (int) $payment->id,
                        'manual_payment_bundle_id' => (int) $bundle->id,
                        'duration_days' => (int) $item['duration_days'],
                        'expires_at' => $expiresAt->toDateTimeString(),
                    ],
                    'created_at' => $now,
                ]);
            }

            $bundle->forceFill([
                'status' => ManualPaymentBundle::STATUS_COMMITTED,
                'audit_state' => (float) $preview['unallocated_amount'] > 0
                    ? ManualPaymentBundle::AUDIT_NEEDS_FINANCE_RESOLUTION
                    : ManualPaymentBundle::AUDIT_RESOLVED,
            ])->save();
        });

        return $this->serializeCommittedBundle($bundle->fresh(['payments.client', 'payments.deal']), false);
    }

    /**
     * @param  ManualPaymentBundle  $bundle
     * @param  list<array<string, mixed>>  $draftItems
     * @param  list<array<string, mixed>>  $successfulActivations
     * @param  list<array<string, mixed>>  $activationFailures
     * @return array<string, mixed>
     */
    private function handleCommitFailure(
        ManualPaymentBundle $bundle,
        array $draftItems,
        array $successfulActivations,
        array $activationFailures,
        int $actorId
    ): array {
        $wpSync = WpSyncService::forPlatform((int) $bundle->platform_id);
        $compensationErrors = [];

        foreach ($successfulActivations as $item) {
            try {
                $wpSync->deactivateClient((int) $item['client_wp_post_id']);
            } catch (\Throwable $exception) {
                $compensationErrors[] = [
                    'item' => $item,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if ($compensationErrors === []) {
            DB::transaction(function () use ($bundle, $draftItems) {
                $paymentIds = collect($draftItems)->pluck('payment_id')->all();
                $newDealIds = collect($draftItems)
                    ->filter(fn (array $item) => (bool) ($item['new_deal_created'] ?? false))
                    ->pluck('target_deal_id')
                    ->all();

                if ($newDealIds !== []) {
                    Deal::query()->whereIn('id', $newDealIds)->delete();
                }

                if ($paymentIds !== []) {
                    Payment::query()->whereIn('id', $paymentIds)->delete();
                }

                $bundle->delete();
            });

            throw ValidationException::withMessages([
                'bundle' => $activationFailures[0]['message'] ?? 'Bundle activation failed and all remote activations were rolled back.',
            ]);
        }

        $failureMessage = $activationFailures[0]['message'] ?? 'Bundle activation failed.';
        $this->markBundleAsCompensationFailed($bundle, $draftItems, $failureMessage, $actorId);

        throw ValidationException::withMessages([
            'bundle' => 'Bundle activation failed and remote compensation is incomplete. Finance review is required.',
        ]);
    }

    /**
     * @param  ManualPaymentBundle  $bundle
     * @param  list<array<string, mixed>>  $draftItems
     */
    private function markBundleAsCompensationFailed(
        ManualPaymentBundle $bundle,
        array $draftItems,
        string $reason,
        ?int $actorId
    ): void {
        DB::transaction(function () use ($bundle, $draftItems, $reason, $actorId) {
            $bundle->forceFill([
                'status' => ManualPaymentBundle::STATUS_COMPENSATION_FAILED,
                'audit_state' => ManualPaymentBundle::AUDIT_NEEDS_FINANCE_RESOLUTION,
            ])->save();

            foreach ($draftItems as $item) {
                $clientId = (int) ($item['client_id'] ?? 0);
                if ($clientId <= 0) {
                    $clientId = (int) (Payment::query()->whereKey((int) ($item['payment_id'] ?? 0))->value('client_id') ?: 0);
                }

                if ($clientId <= 0) {
                    continue;
                }

                TimelineEvent::query()->create([
                    'platform_id' => (int) $bundle->platform_id,
                    'entity_type' => 'client',
                    'entity_id' => $clientId,
                    'event_type' => 'manual_payment_bundle_compensation_failed',
                    'actor_id' => $actorId,
                    'content' => [
                        'bundle_id' => (int) $bundle->id,
                        'reference_root' => (string) $bundle->reference_root,
                        'payment_id' => (int) ($item['payment_id'] ?? 0),
                        'reason' => $reason,
                    ],
                    'created_at' => now(),
                ]);

                ClientNote::query()->create([
                    'client_id' => $clientId,
                    'author_id' => $actorId,
                    'note_type' => 'system',
                    'content' => "Manual payment bundle {$bundle->reference_root} needs finance intervention after compensation failed. {$reason}",
                ]);
            }
        });

        $this->auditService->record([
            'platform_id' => (int) $bundle->platform_id,
            'actor_id' => $actorId,
            'action' => CrmAuditAction::MANUAL_PAYMENT_BUNDLE_COMPENSATION_FAILED,
            'entity_type' => 'manual_payment_bundle',
            'entity_id' => (int) $bundle->id,
            'before_state' => [
                'status' => ManualPaymentBundle::STATUS_COMMITTING,
            ],
            'after_state' => [
                'status' => ManualPaymentBundle::STATUS_COMPENSATION_FAILED,
                'audit_state' => ManualPaymentBundle::AUDIT_NEEDS_FINANCE_RESOLUTION,
                'reason' => $reason,
            ],
            'reason' => $reason,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCommittedBundle(ManualPaymentBundle $bundle, bool $idempotent): array
    {
        $bundle->loadMissing(['payments.client', 'payments.deal']);

        return [
            'bundle' => [
                'id' => (int) $bundle->id,
                'platform_id' => (int) $bundle->platform_id,
                'reference_root' => (string) $bundle->reference_root,
                'total_amount' => (float) $bundle->total_amount,
                'allocated_amount' => (float) $bundle->allocated_amount,
                'unallocated_amount' => (float) $bundle->unallocated_amount,
                'currency' => (string) $bundle->currency,
                'reason' => $bundle->reason,
                'status' => (string) $bundle->status,
                'audit_state' => (string) $bundle->audit_state,
                'idempotency_key' => (string) $bundle->idempotency_key,
                'payments' => $bundle->payments->map(function (Payment $payment) {
                    return [
                        'id' => (int) $payment->id,
                        'deal_id' => $payment->deal_id ? (int) $payment->deal_id : null,
                        'client_id' => $payment->client_id ? (int) $payment->client_id : null,
                        'transaction_reference' => $payment->transaction_reference,
                        'status' => $payment->status,
                        'reconciliation_state' => $payment->reconciliation_state,
                    ];
                })->values()->all(),
            ],
            'idempotent' => $idempotent,
        ];
    }
}
