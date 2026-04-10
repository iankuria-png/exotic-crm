<?php

namespace App\Http\Controllers\CRM;

use App\Exceptions\ManualPaymentReferenceConflictException;
use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\ManualPaymentBundleService;
use App\Services\MarketAuthorizationService;
use App\Support\CrmAuditAction;
use Illuminate\Http\Request;
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
            'items.*.deal_id' => 'required|integer|exists:deals,id',
            'items.*.allocated_amount' => 'nullable|numeric|min:0.01',
            'items.*.duration_days' => 'nullable|integer|min:1|max:365',
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
            'items.*.deal_id' => 'required|integer|exists:deals,id',
            'items.*.allocated_amount' => 'nullable|numeric|min:0.01',
            'items.*.duration_days' => 'nullable|integer|min:1|max:365',
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
}
