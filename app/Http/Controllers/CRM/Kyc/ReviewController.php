<?php

namespace App\Http\Controllers\CRM\Kyc;

use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Models\KycSubject;
use App\Services\Kyc\KycDocumentService;
use App\Services\Kyc\KycSubjectService;
use App\Services\MarketAuthorizationService;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly KycSubjectService $subjectService,
        private readonly KycDocumentService $documentService,
    ) {
    }

    public function show(Request $request, KycSubject $subject)
    {
        $subject->load(['client.platform', 'client.activeDeal.product', 'documents.blob', 'sites', 'reviewer']);
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $subject->client->platform_id);

        return response()->json([
            'subject' => $subject,
            'status_payload' => $this->subjectService->buildStatusPayload($subject),
            'documents' => $subject->documents->map(fn ($document) => [
                'id' => (int) $document->id,
                'kind' => $document->kind,
                'mime' => $document->mime,
                'byte_size' => (int) $document->byte_size,
                'storage_driver' => $document->storage_driver,
                'uploaded_at' => optional($document->uploaded_at)->toIso8601String(),
                'view_url' => $this->documentService->signedViewUrl($document, $request->user()),
            ])->values(),
        ]);
    }

    public function approve(Request $request, KycSubject $subject)
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $subject->client->platform_id);
        $subject = $this->subjectService->markApprovedFromSource($subject, 'kyc', $request->user(), trim((string) $request->input('reason', 'Approved via KYC review')) ?: null);

        return response()->json(['success' => true, 'subject' => $subject->load(['client', 'sites'])]);
    }

    public function reject(Request $request, KycSubject $subject)
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $subject->client->platform_id);
        $validated = $request->validate([
            'reason_user' => 'required|string|max:2000',
            'reason_internal' => 'nullable|string|max:4000',
        ]);

        $subject = $this->subjectService->reject($subject, $validated['reason_user'], $validated['reason_internal'] ?? null, $request->user());

        return response()->json(['success' => true, 'subject' => $subject->load(['client', 'sites'])]);
    }

    public function requestInfo(Request $request, KycSubject $subject)
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $subject->client->platform_id);
        $validated = $request->validate([
            'reason_user' => 'required|string|max:2000',
            'reason_internal' => 'nullable|string|max:4000',
        ]);

        $subject = $this->subjectService->requestInfo($subject, $validated['reason_user'], $validated['reason_internal'] ?? null, $request->user());

        return response()->json(['success' => true, 'subject' => $subject->load(['client', 'sites'])]);
    }

    public function reRequest(Request $request, KycSubject $subject)
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $subject->client->platform_id);
        $subject = $this->subjectService->reRequest($subject, $request->user(), trim((string) $request->input('reason', 'Re-verification requested')) ?: null);

        return response()->json(['success' => true, 'subject' => $subject->load(['client', 'sites'])]);
    }

    public function bulkReRequest(Request $request)
    {
        abort_unless(($request->user()->role ?? '') === 'admin', 403, 'Only admins can bulk re-request verification.');
        $validated = $request->validate([
            'subject_ids' => 'required|array|min:1',
            'subject_ids.*' => 'integer|exists:kyc_subjects,id',
            'reason' => 'nullable|string|max:2000',
        ]);

        $subjects = KycSubject::query()->with('client')->whereIn('id', $validated['subject_ids'])->get();
        foreach ($subjects as $subject) {
            $this->subjectService->reRequest($subject, $request->user(), $validated['reason'] ?? 'Bulk re-verification request');
        }

        return response()->json(['success' => true, 'count' => $subjects->count()]);
    }

    public function deleteDocument(Request $request, KycSubject $subject, KycDocument $document)
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $subject->client->platform_id);
        abort_unless((int) $document->subject_id === (int) $subject->id, 404, 'Document not found for subject.');

        $this->documentService->delete($document, $request->user());

        return response()->json(['success' => true]);
    }
}
