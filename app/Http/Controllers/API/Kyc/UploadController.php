<?php

namespace App\Http\Controllers\API\Kyc;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\KycSubject;
use App\Services\Kyc\KycDocumentService;
use App\Services\Kyc\KycSettingsService;
use App\Services\Kyc\KycSubjectService;
use Illuminate\Http\Request;

class UploadController extends Controller
{
    public function __construct(
        private readonly KycSubjectService $subjectService,
        private readonly KycDocumentService $documentService,
        private readonly KycSettingsService $settingsService,
    ) {
    }

    public function initiate(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'wp_user_id' => 'required|integer',
            'wp_post_id' => 'required|integer',
            'kind' => 'required|in:id_front,id_back,selfie',
            'mime' => 'required|string|max:150',
            'byte_size' => 'required|integer|min:1',
            'sha256' => 'required|string|size:64',
        ]);

        $client = Client::query()
            ->where('platform_id', (int) $validated['platform_id'])
            ->where('wp_post_id', (int) $validated['wp_post_id'])
            ->where('wp_user_id', (int) $validated['wp_user_id'])
            ->firstOrFail();

        $subject = $this->subjectService->resolveOrCreateForClient($client);
        $target = $this->documentService->initiateUpload(
            $subject,
            (string) $validated['kind'],
            (string) $validated['mime'],
            (int) $validated['byte_size'],
            strtolower((string) $validated['sha256']),
            $validated,
        );

        return response()->json(array_merge(
            ['subject_id' => (int) $subject->id],
            $target->toArray(),
        ));
    }

    public function complete(Request $request)
    {
        $validated = $request->validate([
            'subject_id' => 'required|integer|exists:kyc_subjects,id',
            'kind' => 'required|in:id_front,id_back,selfie',
            's3_key' => 'required|string|max:500',
            'mime' => 'required|string|max:150',
            'byte_size' => 'required|integer|min:1',
            'sha256' => 'required|string|size:64',
        ]);

        $subject = KycSubject::query()->findOrFail((int) $validated['subject_id']);
        $document = $this->documentService->completeS3Upload(
            $subject,
            (string) $validated['kind'],
            (string) $validated['s3_key'],
            (string) $validated['mime'],
            (int) $validated['byte_size'],
            strtolower((string) $validated['sha256']),
        );

        return response()->json([
            'success' => true,
            'subject_id' => (int) $subject->id,
            'document_id' => (int) $document->id,
            'status' => $subject->fresh()->status,
        ]);
    }

    public function statusByWp(int $platformId, int $wpUserId)
    {
        $client = Client::query()
            ->where('platform_id', $platformId)
            ->where('wp_user_id', $wpUserId)
            ->firstOrFail();

        $subject = $this->subjectService->resolveOrCreateForClient($client);

        return response()->json($this->subjectService->buildStatusPayload($subject->fresh(['client', 'sites'])));
    }
}
