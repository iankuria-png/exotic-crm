<?php

namespace App\Http\Controllers\CRM\Kyc;

use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Models\KycSubject;
use App\Services\Kyc\KycDocumentService;
use App\Services\Kyc\KycSettingsService;
use App\Services\Kyc\KycSubjectService;
use App\Services\MarketAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReviewController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly KycSubjectService $subjectService,
        private readonly KycDocumentService $documentService,
        private readonly KycSettingsService $settingsService,
    ) {
    }

    public function show(Request $request, KycSubject $subject)
    {
        $subject->load(['client.platform', 'client.activeDeal.product', 'documents.uploadedBy', 'sites', 'reviewer']);
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $subject->client->platform_id);

        return response()->json($this->subjectPayload($subject, $request));
    }

    public function approve(Request $request, KycSubject $subject)
    {
        $this->ensureReviewerCanAct($request, $subject);
        $subject = $this->subjectService->markApprovedFromSource($subject, 'kyc', $request->user(), trim((string) $request->input('reason', 'Approved via KYC review')) ?: null);

        return response()->json(['success' => true, 'subject' => $subject->load(['client', 'sites'])]);
    }

    public function reject(Request $request, KycSubject $subject)
    {
        $this->ensureReviewerCanAct($request, $subject);
        $validated = $request->validate([
            'reason_user' => 'required|string|max:2000',
            'reason_internal' => 'nullable|string|max:4000',
        ]);

        $subject = $this->subjectService->reject($subject, $validated['reason_user'], $validated['reason_internal'] ?? null, $request->user());

        return response()->json(['success' => true, 'subject' => $subject->load(['client', 'sites'])]);
    }

    public function requestInfo(Request $request, KycSubject $subject)
    {
        $this->ensureReviewerCanAct($request, $subject);
        $validated = $request->validate([
            'reason_user' => 'required|string|max:2000',
            'reason_internal' => 'nullable|string|max:4000',
        ]);

        $subject = $this->subjectService->requestInfo($subject, $validated['reason_user'], $validated['reason_internal'] ?? null, $request->user());

        return response()->json(['success' => true, 'subject' => $subject->load(['client', 'sites'])]);
    }

    public function reRequest(Request $request, KycSubject $subject)
    {
        $this->ensureReviewerCanAct($request, $subject);
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

    public function uploadDocument(Request $request, KycSubject $subject)
    {
        $this->ensureReviewerCanAct($request, $subject);

        $maxKilobytes = max(1, (int) ceil($this->settingsService->maxDocBytes() / 1024));
        $validated = $request->validate([
            'kind' => ['required', 'string', Rule::in($this->documentService->allowedDocumentKinds())],
            'upload_source_channel' => ['required', 'string', Rule::in($this->documentService->allowedStaffUploadChannels())],
            'upload_note' => 'required|string|max:4000',
            'file' => ['required', 'file', 'max:' . $maxKilobytes, 'mimetypes:' . implode(',', $this->documentService->allowedMimeTypes())],
        ]);

        $document = $this->documentService->storeStaffUpload(
            $subject,
            $request->file('file'),
            $validated['kind'],
            $request->user(),
            $validated['upload_source_channel'],
            $validated['upload_note'],
        );

        $subject->refresh()->load(['client.platform', 'client.activeDeal.product', 'documents.uploadedBy', 'sites', 'reviewer']);

        return response()->json([
            'success' => true,
            'document' => $this->transformDocument($document->fresh(['subject.client', 'uploadedBy']), $request),
            'subject' => $subject,
            'status_payload' => $this->subjectService->buildStatusPayload($subject),
            'documents' => $subject->documents->map(fn (KycDocument $entry) => $this->transformDocument($entry, $request))->values(),
            'review_capabilities' => [
                'allowed_document_kinds' => $this->documentService->allowedDocumentKinds(),
                'allowed_staff_upload_channels' => $this->documentService->allowedStaffUploadChannels(),
                'allowed_mime_types' => $this->documentService->allowedMimeTypes(),
            ],
        ], 201);
    }

    public function deleteDocument(Request $request, KycSubject $subject, KycDocument $document)
    {
        $this->ensureReviewerCanAct($request, $subject);
        abort_unless((int) $document->subject_id === (int) $subject->id, 404, 'Document not found for subject.');

        $this->documentService->delete($document, $request->user());

        return response()->json(['success' => true]);
    }

    private function ensureReviewerCanAct(Request $request, KycSubject $subject): void
    {
        $this->marketAuthorizationService->ensureRole($request->user(), ['admin', 'sub_admin', 'sales'], 'Only admin, sub-admin, or sales users can perform KYC review actions.');
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $subject->client->platform_id);
    }

    private function subjectPayload(KycSubject $subject, Request $request): array
    {
        return [
            'subject' => $subject,
            'status_payload' => $this->subjectService->buildStatusPayload($subject),
            'documents' => $subject->documents->map(fn (KycDocument $document) => $this->transformDocument($document, $request))->values(),
            'review_capabilities' => [
                'allowed_document_kinds' => $this->documentService->allowedDocumentKinds(),
                'allowed_staff_upload_channels' => $this->documentService->allowedStaffUploadChannels(),
                'allowed_mime_types' => $this->documentService->allowedMimeTypes(),
            ],
        ];
    }

    private function transformDocument(KycDocument $document, Request $request): array
    {
        return [
            'id' => (int) $document->id,
            'kind' => $document->kind,
            'mime' => $document->mime,
            'byte_size' => (int) $document->byte_size,
            'storage_driver' => $document->storage_driver,
            'original_filename' => $document->original_filename,
            'upload_origin' => $document->upload_origin,
            'upload_source_channel' => $document->upload_source_channel,
            'upload_note' => $document->upload_note,
            'uploaded_by_user_id' => $document->uploaded_by_user_id ? (int) $document->uploaded_by_user_id : null,
            'uploaded_by_name' => $document->uploadedBy?->name,
            'uploaded_at' => optional($document->uploaded_at)->toIso8601String(),
            'view_url' => $this->documentService->signedViewUrl($document, $request->user()),
        ];
    }
}
