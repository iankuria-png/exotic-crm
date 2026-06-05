<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentReconciliationBatch;
use App\Models\PaymentReconciliationRow;
use App\Models\Platform;
use App\Services\AuditService;
use App\Services\MarketAuthorizationService;
use App\Services\PaymentReconciliationAuditService;
use App\Support\CrmAuditAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PaymentReconciliationController extends Controller
{
    public function __construct(
        private readonly PaymentReconciliationAuditService $reconciliationService,
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly AuditService $auditService
    ) {
    }

    public function preview(Request $request)
    {
        // Accept either a single platform_id (legacy) or platform_ids[] for multi-market batches.
        $request->merge([
            'platform_ids' => $this->normalizePlatformIdsInput($request),
        ]);

        $validator = Validator::make($request->all(), [
            'platform_ids' => 'required|array|min:1',
            'platform_ids.*' => 'integer|exists:platforms,id',
            'file' => 'nullable|required_without:pasted_text|file|mimes:csv,txt,xlsx,xml|max:20480',
            'pasted_text' => [
                'nullable',
                'required_without:file',
                'string',
                function (string $attribute, $value, \Closure $fail): void {
                    if ($value !== null && trim((string) $value) === '') {
                        $fail('Paste content cannot be empty.');
                    }
                },
            ],
            'has_header' => 'nullable|boolean',
            'reason' => 'required|string|max:500',
        ]);

        $validated = $validator->validate();
        $platformIds = array_values(array_unique(array_map('intval', $validated['platform_ids'])));

        // The user must be authorized for every selected market.
        foreach ($platformIds as $platformId) {
            $this->marketAuthorizationService->ensureUserCanAccessPlatform(
                $request->user(),
                $platformId,
                'You do not have access to one of the selected payment markets.'
            );
        }

        // Preserve selection order so the first market becomes the primary (used for audit scope).
        $platforms = Platform::query()->whereIn('id', $platformIds)->get()->keyBy('id');
        $orderedPlatforms = array_values(array_filter(array_map(
            fn(int $id) => $platforms->get($id),
            $platformIds
        )));

        $input = $request->hasFile('file')
            ? $validated['file']
            : (string) ($validated['pasted_text'] ?? '');

        try {
            $result = $this->reconciliationService->preview(
                $input,
                $orderedPlatforms,
                (int) $request->user()->id,
                (bool) ($validated['has_header'] ?? true),
                (string) $validated['reason']
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->auditService->fromRequest(
            $request,
            $platformIds[0],
            CrmAuditAction::PAYMENT_RECON_PREVIEW,
            'payment_reconciliation_batch',
            (int) $result['batch_id'],
            null,
            [
                'summary' => $result['summary'] ?? [],
                'source_type' => $result['source_type'] ?? null,
                'platform_ids' => $platformIds,
                'file_name' => $request->hasFile('file') ? $validated['file']->getClientOriginalName() : null,
            ],
            (string) $validated['reason']
        );

        return response()->json($result);
    }

    /**
     * Resolve the selected markets from either platform_ids[] or a single platform_id.
     *
     * @return array<int,int>
     */
    private function normalizePlatformIdsInput(Request $request): array
    {
        $ids = $request->input('platform_ids', []);
        if (!is_array($ids)) {
            $ids = array_filter(array_map('trim', explode(',', (string) $ids)), fn($v) => $v !== '');
        }

        if (empty($ids) && $request->filled('platform_id')) {
            $ids = [$request->input('platform_id')];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function batches(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'status' => 'nullable|in:reviewing,closed',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if (!empty($validated['platform_id'])) {
            $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $validated['platform_id']);
        }

        $query = PaymentReconciliationBatch::query()
            ->with(['platform:id,name,country,currency_code', 'uploader:id,name,email', 'closedBy:id,name,email'])
            ->orderByDesc('created_at');

        $this->marketAuthorizationService->applyPlatformScope($query, $request->user());

        if (!empty($validated['platform_id'])) {
            $query->where('platform_id', (int) $validated['platform_id']);
        }

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $batches = $query->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($batches->items())->map(fn(PaymentReconciliationBatch $batch) => $this->serializeBatch($batch))->values(),
            'meta' => [
                'current_page' => $batches->currentPage(),
                'last_page' => $batches->lastPage(),
                'per_page' => $batches->perPage(),
                'total' => $batches->total(),
            ],
        ]);
    }

    public function show(Request $request, PaymentReconciliationBatch $batch)
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $batch->platform_id);

        $validated = $request->validate([
            'classification' => 'nullable|in:matched,amount_mismatch,missing,unverifiable,duplicate_in_file,duplicate_in_crm',
            'review_status' => 'nullable|in:pending,reviewing,confirmed_fraud,cleared,linked,resolved',
            'search' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $query = $batch->rows()
            ->with([
                'matchedPayment:id,platform_id,client_id,amount,currency,transaction_reference,status,reconciliation_state,confirmed_by',
                'matchedClient:id,name',
                'matchedPlatform:id,name,currency_code',
                'confirmedBy:id,name,email',
                'reviewedBy:id,name,email',
            ])
            ->orderBy('row_number');

        if (!empty($validated['classification'])) {
            $query->where('classification', $validated['classification']);
        }

        if (($validated['review_status'] ?? '') === 'resolved') {
            $query->whereIn('review_status', ['confirmed_fraud', 'cleared', 'linked']);
        } elseif (!empty($validated['review_status'])) {
            $query->where('review_status', $validated['review_status']);
        }

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $referenceLike = '%' . preg_replace('/[^A-Za-z0-9]/', '', $search) . '%';
            $query->where(function ($builder) use ($like, $referenceLike) {
                $builder->where('external_name', 'like', $like)
                    ->orWhere('external_reference_raw', 'like', $like)
                    ->orWhere('transaction_reference_norm', 'like', $referenceLike);
            });
        }

        $rows = $query->paginate((int) ($validated['per_page'] ?? 50));
        collect($rows->items())->each(fn(PaymentReconciliationRow $row) => $row->setRelation('batch', $batch));

        return response()->json([
            'batch' => $this->serializeBatch($batch->fresh(['platform:id,name,country,currency_code', 'uploader:id,name,email', 'closedBy:id,name,email'])),
            'summary' => $this->batchSummary($batch),
            'rows' => collect($rows->items())->map(fn(PaymentReconciliationRow $row) => $this->serializeRow($row))->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function close(Request $request, PaymentReconciliationBatch $batch)
    {
        return $this->transitionBatch($request, $batch, 'closed');
    }

    public function reopen(Request $request, PaymentReconciliationBatch $batch)
    {
        return $this->transitionBatch($request, $batch, 'reviewing');
    }

    public function review(Request $request, PaymentReconciliationRow $row)
    {
        $row->loadMissing('batch');
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $row->batch->platform_id);

        $validated = $request->validate([
            'status' => 'required|in:pending,reviewing,confirmed_fraud,cleared',
            'note' => 'nullable|string|max:1000',
            'reason' => 'required|string|max:500',
        ]);

        try {
            $result = $this->reconciliationService->updateRowReview(
                $row,
                (string) $validated['status'],
                $validated['note'] ?? null,
                (int) $request->user()->id
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->auditService->fromRequest(
            $request,
            (int) $result['row']->batch->platform_id,
            CrmAuditAction::PAYMENT_RECON_ROW_REVIEW,
            'payment_reconciliation_row',
            (int) $result['row']->id,
            $result['before'],
            $result['after'],
            (string) $validated['reason']
        );

        return response()->json([
            'message' => 'Review status updated.',
            'row' => $this->serializeRow($result['row']->fresh(['matchedPayment', 'matchedClient', 'matchedPlatform', 'confirmedBy', 'reviewedBy', 'batch'])),
            'summary' => $this->batchSummary($result['row']->batch->fresh()),
        ]);
    }

    public function bulkReview(Request $request, PaymentReconciliationBatch $batch)
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $batch->platform_id);

        $validated = $request->validate([
            'row_ids' => 'required|array|min:1',
            'row_ids.*' => 'integer',
            'status' => 'required|in:pending,reviewing,confirmed_fraud,cleared',
            'note' => 'nullable|string|max:1000',
            'reason' => 'required|string|max:500',
        ]);

        $before = [
            'resolved_rows' => (int) $batch->resolved_rows,
        ];

        try {
            $result = $this->reconciliationService->bulkUpdateReview(
                $batch,
                array_map('intval', $validated['row_ids']),
                (string) $validated['status'],
                $validated['note'] ?? null,
                (int) $request->user()->id
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->auditService->fromRequest(
            $request,
            (int) $batch->platform_id,
            CrmAuditAction::PAYMENT_RECON_ROW_REVIEW,
            'payment_reconciliation_batch',
            (int) $batch->id,
            $before,
            [
                'status' => $result['status'],
                'rows_updated' => $result['updated'],
                'row_ids' => array_map('intval', $validated['row_ids']),
                'resolved_rows' => (int) $result['batch']->resolved_rows,
            ],
            (string) $validated['reason']
        );

        return response()->json([
            'message' => $result['updated'] . ' row(s) updated.',
            'updated' => $result['updated'],
            'summary' => $this->batchSummary($result['batch']),
        ]);
    }

    public function link(Request $request, PaymentReconciliationRow $row)
    {
        $row->loadMissing('batch');
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $row->batch->platform_id);

        $validated = $request->validate([
            'payment_id' => 'required|integer|exists:payments,id',
            'note' => 'nullable|string|max:1000',
            'reason' => 'required|string|max:500',
        ]);

        $payment = Payment::query()->findOrFail((int) $validated['payment_id']);
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $payment->platform_id);

        try {
            $result = $this->reconciliationService->linkRowToPayment(
                $row,
                $payment,
                (int) $request->user()->id,
                $validated['note'] ?? null
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->auditService->fromRequest(
            $request,
            (int) $result['row']->batch->platform_id,
            CrmAuditAction::PAYMENT_RECON_ROW_LINK,
            'payment_reconciliation_row',
            (int) $result['row']->id,
            $result['row_before'],
            $result['row_after'],
            (string) $validated['reason']
        );

        $this->auditService->fromRequest(
            $request,
            (int) $result['payment']->platform_id,
            CrmAuditAction::PAYMENT_REVIEW_STATE_UPDATE,
            'payment',
            (int) $result['payment']->id,
            $result['payment_before'],
            $result['payment_after'],
            (string) $validated['reason']
        );

        return response()->json([
            'message' => 'Row linked to payment.',
            'row' => $this->serializeRow($result['row']->fresh(['matchedPayment', 'matchedClient', 'matchedPlatform', 'confirmedBy', 'reviewedBy', 'batch'])),
            'payment' => $result['payment'],
            'summary' => $this->batchSummary($result['row']->batch->fresh()),
        ]);
    }

    public function candidates(Request $request, PaymentReconciliationRow $row)
    {
        $row->loadMissing('batch');
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $row->batch->platform_id);

        try {
            $candidates = $this->reconciliationService->candidatesForRow($row);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $candidates]);
    }

    public function template(): \Symfony\Component\HttpFoundation\Response
    {
        $headers = ['Client Name', 'Amount Paid', 'Date Paid', 'Transaction ID', 'Activated', 'Who Activated', 'CRM Transaction ID'];
        $sample = ['Sample Client', '10000', '29th April', 'T_SAMPLE12345', '', '', ''];

        $content = implode(',', $headers) . "\n" . implode(',', $sample) . "\n";

        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="payment-fraud-audit-template.csv"',
        ]);
    }

    private function transitionBatch(Request $request, PaymentReconciliationBatch $batch, string $status)
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $batch->platform_id);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $before = [
            'status' => $batch->status,
            'closed_by' => $batch->closed_by ? (int) $batch->closed_by : null,
            'closed_at' => $batch->closed_at?->toDateTimeString(),
        ];

        if ($status === 'closed') {
            $batch->update([
                'status' => 'closed',
                'closed_by' => (int) $request->user()->id,
                'closed_at' => now(),
            ]);
            $message = 'Reconciliation batch closed.';
        } else {
            $batch->update([
                'status' => 'reviewing',
                'closed_by' => null,
                'closed_at' => null,
            ]);
            $message = 'Reconciliation batch reopened.';
        }

        $batch->refresh();
        $after = [
            'status' => $batch->status,
            'closed_by' => $batch->closed_by ? (int) $batch->closed_by : null,
            'closed_at' => $batch->closed_at?->toDateTimeString(),
        ];

        $this->auditService->fromRequest(
            $request,
            (int) $batch->platform_id,
            CrmAuditAction::PAYMENT_RECON_BATCH_CLOSE,
            'payment_reconciliation_batch',
            (int) $batch->id,
            $before,
            $after,
            (string) $validated['reason']
        );

        return response()->json([
            'message' => $message,
            'batch' => $this->serializeBatch($batch->fresh(['platform:id,name,country,currency_code', 'uploader:id,name,email', 'closedBy:id,name,email'])),
        ]);
    }

    private function serializeBatch(?PaymentReconciliationBatch $batch): ?array
    {
        if (!$batch) {
            return null;
        }

        $marketsMeta = collect($batch->metadata['markets'] ?? [])
            ->map(fn($market) => [
                'id' => (int) ($market['id'] ?? 0),
                'name' => $market['name'] ?? null,
                'currency_code' => $market['currency_code'] ?? null,
            ])
            ->filter(fn($market) => $market['id'] > 0)
            ->values()
            ->all();

        return [
            'id' => (int) $batch->id,
            'platform_id' => (int) $batch->platform_id,
            'platform_ids' => $batch->platformIdSet(),
            'fallback_currency' => $batch->fallback_currency,
            'platform' => $batch->platform ? [
                'id' => (int) $batch->platform->id,
                'name' => $batch->platform->name,
                'country' => $batch->platform->country,
                'currency_code' => $batch->platform->currency_code,
            ] : null,
            'markets' => $marketsMeta,
            'uploaded_by' => $batch->uploaded_by ? (int) $batch->uploaded_by : null,
            'uploader' => $batch->uploader ? [
                'id' => (int) $batch->uploader->id,
                'name' => $batch->uploader->name,
                'email' => $batch->uploader->email,
            ] : null,
            'file_name' => $batch->file_name,
            'file_mime' => $batch->file_mime,
            'source_type' => $batch->source_type,
            'status' => $batch->status,
            'reason' => $batch->reason,
            'closed_by' => $batch->closed_by ? (int) $batch->closed_by : null,
            'closed_at' => $batch->closed_at?->toDateTimeString(),
            'closed_user' => $batch->closedBy ? [
                'id' => (int) $batch->closedBy->id,
                'name' => $batch->closedBy->name,
            ] : null,
            'metadata' => $batch->metadata,
            'summary' => $this->batchSummary($batch),
            'created_at' => $batch->created_at?->toDateTimeString(),
            'updated_at' => $batch->updated_at?->toDateTimeString(),
        ];
    }

    private function serializeRow(PaymentReconciliationRow $row): array
    {
        return [
            'id' => (int) $row->id,
            'row_number' => (int) $row->row_number,
            'raw_row' => $row->raw_row,
            'external_name' => $row->external_name,
            'external_amount' => $row->external_amount !== null ? (float) $row->external_amount : null,
            'external_currency' => $row->external_currency,
            'external_paid_at_text' => $row->external_paid_at_text,
            'external_reference_raw' => $row->external_reference_raw,
            'transaction_reference_norm' => $row->transaction_reference_norm,
            'classification' => $row->classification,
            'flags' => $row->flags,
            'matched_payment_id' => $row->matched_payment_id ? (int) $row->matched_payment_id : null,
            'matched_payment' => $row->matchedPayment ? [
                'id' => (int) $row->matchedPayment->id,
                'amount' => (float) $row->matchedPayment->amount,
                'currency' => $row->matchedPayment->currency,
                'transaction_reference' => $row->matchedPayment->transaction_reference,
                'status' => $row->matchedPayment->status,
                'reconciliation_state' => $row->matchedPayment->reconciliation_state,
            ] : null,
            'matched_client_id' => $row->matched_client_id ? (int) $row->matched_client_id : null,
            'matched_client' => $row->matchedClient ? [
                'id' => (int) $row->matchedClient->id,
                'name' => $row->matchedClient->name,
            ] : null,
            'matched_platform_id' => $row->matched_platform_id ? (int) $row->matched_platform_id : null,
            'matched_platform' => $row->matchedPlatform ? [
                'id' => (int) $row->matchedPlatform->id,
                'name' => $row->matchedPlatform->name,
                'currency_code' => $row->matchedPlatform->currency_code,
            ] : null,
            // Currency to display this row in: matched payment first, else the resolved sheet/batch currency.
            'display_currency' => $row->matchedPayment?->currency
                ?: ($row->external_currency ?: ($row->matchedPlatform?->currency_code ?: $row->batch?->fallback_currency)),
            'matched_confirmed_by' => $row->matched_confirmed_by ? (int) $row->matched_confirmed_by : null,
            'confirmed_by' => $row->confirmedBy ? [
                'id' => (int) $row->confirmedBy->id,
                'name' => $row->confirmedBy->name,
                'email' => $row->confirmedBy->email,
            ] : null,
            'match_basis' => $row->match_basis,
            'review_status' => $row->review_status,
            'review_note' => $row->review_note,
            'reviewed_by' => $row->reviewed_by ? (int) $row->reviewed_by : null,
            'reviewer' => $row->reviewedBy ? [
                'id' => (int) $row->reviewedBy->id,
                'name' => $row->reviewedBy->name,
            ] : null,
            'reviewed_at' => $row->reviewed_at?->toDateTimeString(),
            'created_at' => $row->created_at?->toDateTimeString(),
        ];
    }

    private function batchSummary(PaymentReconciliationBatch $batch): array
    {
        // Sum the external (collected) amount per classification — the "amount at risk", especially
        // for the Missing bucket. Money totals are only meaningful when the batch shares one currency.
        $amountByClassification = $batch->rows()
            ->selectRaw('classification, COALESCE(SUM(external_amount), 0) as total')
            ->groupBy('classification')
            ->pluck('total', 'classification');

        $sum = fn(array $keys) => round((float) collect($keys)->sum(fn($key) => (float) ($amountByClassification[$key] ?? 0)), 2);

        return [
            'total_rows' => (int) $batch->total_rows,
            'matched_rows' => (int) $batch->matched_rows,
            'mismatch_rows' => (int) $batch->mismatch_rows,
            'missing_rows' => (int) $batch->missing_rows,
            'unverifiable_rows' => (int) $batch->unverifiable_rows,
            'duplicate_rows' => (int) $batch->duplicate_rows,
            'resolved_rows' => (int) $batch->resolved_rows,
            'summary_currency' => $batch->fallback_currency,
            'amounts' => [
                'total' => $sum(['matched', 'amount_mismatch', 'missing', 'unverifiable', 'duplicate_in_file', 'duplicate_in_crm']),
                'matched' => $sum(['matched']),
                'mismatch' => $sum(['amount_mismatch']),
                'missing' => $sum(['missing']),
                'unverifiable' => $sum(['unverifiable']),
                'duplicate' => $sum(['duplicate_in_file', 'duplicate_in_crm']),
            ],
        ];
    }
}
