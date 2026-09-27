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
    ) {}

    public function initiate(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'wp_user_id' => 'required|integer',
            'wp_post_id' => 'required|integer',
            'capture_set_id' => 'nullable|uuid',
            'document_type' => 'required_with:capture_set_id|in:national_id,passport,driving_licence,alien_id',
            'sequence' => 'nullable|integer|min:0|max:2',
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
        if (! empty($validated['capture_set_id'])) {
            abort_unless($this->settingsService->isPlatformEnabled((int) $client->platform_id), 422, 'Verification is unavailable in this market.');
            abort_unless(in_array($validated['document_type'], $this->settingsService->documentTypes((int) $client->platform_id), true), 422, 'Unsupported document type.');
            abort_if($validated['kind'] !== 'selfie' && ($validated['sequence'] ?? 0) !== 0, 422, 'Only selfies have pose frames.');
            abort_unless(in_array($validated['mime'], ['image/jpeg', 'image/png', 'image/webp']), 422, 'Use a JPG, PNG or WebP photo.');
            \Illuminate\Support\Facades\DB::transaction(function () use ($subject, $validated) {
                $locked = KycSubject::query()->lockForUpdate()->findOrFail($subject->id);
                if ($locked->capture_set_id !== $validated['capture_set_id'] || $locked->document_type !== $validated['document_type']) {
                    $locked->forceFill(['capture_set_id' => $validated['capture_set_id'], 'document_type' => $validated['document_type'], 'submitted_document_ids' => null, 'submitted_at' => null, 'ai_consent_at' => null, 'review_version' => $locked->review_version + 1])->save();
                }
            });
            $subject->refresh();
        }
        $target = $this->documentService->initiateUpload(
            $subject,
            (string) $validated['kind'],
            (string) $validated['mime'],
            (int) $validated['byte_size'],
            strtolower((string) $validated['sha256']),
            $validated,
        );

        $targetData = $target->toArray();
        if ($targetData['mode'] === 's3') {
            \Illuminate\Support\Facades\Cache::put('kyc-upload:'.hash('sha256', $targetData['s3_key']), $validated + ['subject_id' => $subject->id], now()->addMinutes(15));
        }

        return response()->json(array_merge(
            ['subject_id' => (int) $subject->id],
            $target->toArray(),
        ));
    }

    public function complete(Request $request)
    {
        $validated = $request->validate([
            'subject_id' => 'required|integer|exists:kyc_subjects,id',
            'capture_set_id' => 'nullable|uuid',
            'document_type' => 'required_with:capture_set_id|in:national_id,passport,driving_licence,alien_id',
            'sequence' => 'nullable|integer|min:0|max:2',
            'kind' => 'required|in:id_front,id_back,selfie',
            's3_key' => 'required|string|max:500',
            'mime' => 'required|string|max:150',
            'byte_size' => 'required|integer|min:1',
            'sha256' => 'required|string|size:64',
        ]);

        $subject = KycSubject::query()->findOrFail((int) $validated['subject_id']);
        $claims = \Illuminate\Support\Facades\Cache::get('kyc-upload:'.hash('sha256', $validated['s3_key']));
        abort_unless(is_array($claims) && (int) $claims['subject_id'] === (int) $subject->id, 403, 'Upload authorization expired. Please retry.');
        foreach (['kind', 'mime', 'byte_size', 'sha256'] as $field) {
            abort_unless((string) $claims[$field] === (string) $validated[$field], 422, 'Upload metadata does not match.');
        }
        $raw = \Illuminate\Support\Facades\Storage::disk('s3_kyc')->get($validated['s3_key']);
        abort_unless(is_string($raw) && strlen($raw) === (int) $validated['byte_size'] && hash_equals($validated['sha256'], hash('sha256', $raw)), 422, 'Uploaded photo could not be verified.');
        unset($raw);
        $document = $this->documentService->completeS3Upload(
            $subject,
            (string) $validated['kind'],
            (string) $validated['s3_key'],
            (string) $validated['mime'],
            (int) $validated['byte_size'],
            strtolower((string) $validated['sha256']),
            $claims,
        );

        return response()->json([
            'success' => true,
            'subject_id' => (int) $subject->id,
            'document_id' => (int) $document->id,
            'status' => $subject->fresh()->status,
        ]);
    }

    public function submit(Request $request)
    {
        $payload = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'wp_user_id' => 'required|integer', 'wp_post_id' => 'required|integer',
            'document_type' => 'required|in:national_id,passport,driving_licence,alien_id',
            'capture_set_id' => 'required|uuid', 'consent' => 'required|accepted',
            'document_ids' => 'required|array|min:2|max:5', 'document_ids.*' => 'required|integer|distinct',
        ]);
        $subject = $this->subjectService->resolveByWpIdentity($payload['platform_id'], $payload['wp_user_id'], $payload['wp_post_id']);
        abort_unless($subject, 404, 'Profile not found.');

        return response()->json(app(\App\Services\Kyc\Ai\KycAiReviewService::class)->submit($subject, $payload));
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
