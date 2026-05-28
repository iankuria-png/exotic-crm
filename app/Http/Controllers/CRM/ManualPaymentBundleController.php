<?php

namespace App\Http\Controllers\CRM;

use App\Exceptions\ManualPaymentReferenceConflictException;
use App\Http\Controllers\Controller;
use App\Models\ManualPaymentBundle;
use App\Services\AuditService;
use App\Services\ManualPaymentBundleService;
use App\Services\MarketAuthorizationService;
use App\Support\CrmAuditAction;
use App\Support\DealDeactivationReason;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManualPaymentBundleController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly ManualPaymentBundleService $manualPaymentBundleService,
        private readonly AuditService $auditService
    ) {
    }

    public function preview(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'reference_root' => 'required|string|max:255',
            'total_amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|max:10',
            'reason' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.client_id' => 'required|integer|exists:clients,id',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.duration' => 'nullable|string|in:weekly,biweekly,monthly,quarterly,annually',
            'items.*.product_price_id' => 'nullable|integer|exists:product_prices,id',
            'items.*.allocated_amount' => 'nullable|numeric|min:0.01',
        ]);

        $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this payment market.'
        );

        try {
            $preview = $this->manualPaymentBundleService->preview($validated);
        } catch (ManualPaymentReferenceConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'conflict' => $exception->conflict(),
            ], 409);
        }

        $this->auditService->fromRequest(
            $request,
            (int) $validated['platform_id'],
            CrmAuditAction::MANUAL_PAYMENT_BUNDLE_PREVIEW,
            'platform',
            (int) $validated['platform_id'],
            null,
            [
                'reference_root' => $preview['reference_root'],
                'item_count' => count($preview['items']),
                'total_amount' => $preview['total_amount'],
                'allocated_total' => $preview['allocated_total'],
                'unallocated_amount' => $preview['unallocated_amount'],
            ],
            trim((string) ($validated['reason'] ?? '')) !== '' ? (string) $validated['reason'] : 'Manual payment bundle preview'
        );

        return response()->json($preview);
    }

    public function commit(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'reference_root' => 'required|string|max:255',
            'total_amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|max:10',
            'reason' => 'nullable|string|max:500',
            'discount_pin' => ['nullable', 'regex:/^\d{4,6}$/'],
            'idempotency_key' => 'required|string|max:191',
            'items' => 'required|array|min:1',
            'items.*.client_id' => 'required|integer|exists:clients,id',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.duration' => 'nullable|string|in:weekly,biweekly,monthly,quarterly,annually',
            'items.*.product_price_id' => 'nullable|integer|exists:product_prices,id',
            'items.*.allocated_amount' => 'nullable|numeric|min:0.01',
        ]);

        $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this payment market.'
        );

        try {
            $result = $this->manualPaymentBundleService->commit($validated, (int) $request->user()->id);
        } catch (ManualPaymentReferenceConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'conflict' => $exception->conflict(),
            ], 409);
        } catch (ValidationException $exception) {
            throw $exception;
        }

        $bundle = $result['bundle'] ?? null;
        if (is_array($bundle) && !empty($bundle['id'])) {
            $this->auditService->fromRequest(
                $request,
                (int) $validated['platform_id'],
                CrmAuditAction::MANUAL_PAYMENT_BUNDLE_COMMIT,
                'manual_payment_bundle',
                (int) $bundle['id'],
                null,
                [
                    'reference_root' => $bundle['reference_root'] ?? null,
                    'status' => $bundle['status'] ?? null,
                    'audit_state' => $bundle['audit_state'] ?? null,
                    'payment_count' => count($bundle['payments'] ?? []),
                ],
                trim((string) ($validated['reason'] ?? '')) !== '' ? (string) $validated['reason'] : 'Manual payment bundle committed'
            );
        }

        return response()->json($result, 201);
    }

    public function referenceCheck(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'reference_root' => 'required|string|max:255',
        ]);

        $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this payment market.'
        );

        $conflict = $this->manualPaymentBundleService->findReferenceConflict(
            (int) $validated['platform_id'],
            (string) $validated['reference_root']
        );

        return response()->json([
            'reference_root' => $this->manualPaymentBundleService->normalizeReferenceRoot((string) $validated['reference_root']),
            'available' => $conflict === null,
            'conflict' => $conflict,
        ]);
    }

    public function show(Request $request, int $id)
    {
        $bundle = ManualPaymentBundle::query()
            ->with(['payments.client', 'payments.deal', 'createdBy', 'platform'])
            ->findOrFail($id);

        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $bundle->platform_id,
            'You do not have access to this bundle market.'
        );

        $divergence = $this->manualPaymentBundleService->detectDivergence($bundle);

        return response()->json([
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
                'created_by' => $bundle->createdBy ? [
                    'id' => (int) $bundle->createdBy->id,
                    'name' => (string) $bundle->createdBy->name,
                ] : null,
                'created_at' => $bundle->created_at?->toDateTimeString(),
                'payments' => $bundle->payments->map(function ($payment) {
                    return [
                        'id' => (int) $payment->id,
                        'deal_id' => $payment->deal_id ? (int) $payment->deal_id : null,
                        'client_id' => $payment->client_id ? (int) $payment->client_id : null,
                        'client_name' => $payment->client?->name,
                        'transaction_reference' => $payment->transaction_reference,
                        'reference_sequence' => $payment->reference_sequence,
                        'amount' => (float) $payment->amount,
                        'status' => $payment->status,
                        'resolution_code' => $payment->resolution_code,
                        'reconciliation_state' => $payment->reconciliation_state,
                        'deal_status' => $payment->deal?->status,
                    ];
                })->values()->all(),
            ],
            'divergence' => $divergence,
        ]);
    }

    public function approve(Request $request, int $id)
    {
        $bundle = ManualPaymentBundle::query()
            ->with(['payments.client', 'payments.deal', 'createdBy', 'platform'])
            ->findOrFail($id);

        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $bundle->platform_id,
            'You do not have access to this bundle market.'
        );

        try {
            $result = $this->manualPaymentBundleService->approveBundle(
                $bundle,
                (int) $request->user()->id
            );
        } catch (ValidationException $exception) {
            throw $exception;
        }

        $this->auditService->fromRequest(
            $request,
            (int) $bundle->platform_id,
            CrmAuditAction::MANUAL_PAYMENT_BUNDLE_APPROVE,
            'manual_payment_bundle',
            (int) $bundle->id,
            null,
            [
                'reference_root' => $bundle->reference_root,
                'payment_count' => $bundle->payments->count(),
                'total_amount' => (float) $bundle->total_amount,
            ],
            "Bundle approved: {$bundle->reference_root}"
        );

        return response()->json($result);
    }

    public function void(Request $request, int $id)
    {
        $bundle = ManualPaymentBundle::query()
            ->with(['payments.deal.client.platform', 'payments.client'])
            ->findOrFail($id);

        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $bundle->platform_id,
            'You do not have access to this bundle market.'
        );

        $validated = $request->validate([
            'reason_code' => [
                'required',
                Rule::in(array_map(fn ($case) => $case->value, DealDeactivationReason::cases())),
            ],
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $result = $this->manualPaymentBundleService->voidBundle(
                $bundle,
                $validated,
                (int) $request->user()->id
            );
        } catch (ValidationException $exception) {
            throw $exception;
        }

        $this->auditService->fromRequest(
            $request,
            (int) $bundle->platform_id,
            CrmAuditAction::MANUAL_PAYMENT_BUNDLE_VOID,
            'manual_payment_bundle',
            (int) $bundle->id,
            null,
            [
                'reference_root' => $bundle->reference_root,
                'reason_code' => $validated['reason_code'],
                'notes' => $validated['notes'] ?? null,
            ],
            "Bundle voided: {$validated['reason_code']}"
        );

        return response()->json($result);
    }
}
