<?php

namespace App\Services\Kyc;

use App\Models\KycDocument;
use App\Models\KycDocumentBlob;
use App\Models\KycSubject;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Kyc\KycStorage\DbStorage;
use App\Services\Kyc\KycStorage\S3Storage;
use App\Services\Kyc\KycStorage\StorageDriver;
use App\Services\Kyc\KycStorage\UploadTarget;
use App\Services\MarketAuthorizationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class KycDocumentService
{
    public function __construct(
        private readonly KycSettingsService $settingsService,
        private readonly KycSubjectService $subjectService,
        private readonly AuditService $auditService,
        private readonly MarketAuthorizationService $marketAuthorizationService,
    ) {
    }

    public function initiateUpload(KycSubject $subject, string $kind, string $mime, int $byteSize, string $sha256, array $context = []): UploadTarget
    {
        if ($byteSize <= 0 || $byteSize > $this->settingsService->maxDocBytes()) {
            throw new InvalidArgumentException('File is too large or empty.');
        }

        if (!preg_match('/^[a-f0-9]{64}$/i', $sha256)) {
            throw new InvalidArgumentException('Invalid SHA256 digest.');
        }

        return $this->driverForMode($this->settingsService->activeStorageDriver())
            ->initiate($subject, $kind, $mime, $byteSize, strtolower($sha256), $context);
    }

    public function storeDbUploadFromToken(array $claims, UploadedFile $file): KycDocument
    {
        $subject = KycSubject::query()->findOrFail((int) ($claims['subject_id'] ?? 0));
        $kind = (string) ($claims['kind'] ?? '');
        $maxBytes = (int) ($claims['max_bytes'] ?? 0);
        $expectedSha = strtolower((string) ($claims['sha256'] ?? ''));
        $mime = (string) ($claims['mime'] ?? ($file->getMimeType() ?: 'application/octet-stream'));

        if ($file->getSize() > $maxBytes) {
            throw new InvalidArgumentException('File exceeds the allowed size.');
        }

        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            throw new InvalidArgumentException('Unable to read uploaded file.');
        }

        $actualSha = hash('sha256', $contents);
        if (!hash_equals($expectedSha, $actualSha)) {
            throw new InvalidArgumentException('Uploaded file hash mismatch.');
        }

        /** @var DbStorage $driver */
        $driver = $this->driverForMode('db');
        $ciphertext = $driver->encryptRaw($contents);

        return DB::transaction(function () use ($subject, $kind, $mime, $contents, $actualSha, $file, $ciphertext) {
            KycDocument::query()->where('subject_id', (int) $subject->id)->where('kind', $kind)->delete();

            $document = KycDocument::query()->create([
                'subject_id' => (int) $subject->id,
                'kind' => $kind,
                'storage_driver' => 'db',
                'mime' => $mime,
                'byte_size' => strlen($contents),
                'sha256' => $actualSha,
                'original_filename' => $file->getClientOriginalName(),
                'uploaded_at' => now(),
            ]);

            KycDocumentBlob::query()->create([
                'document_id' => (int) $document->id,
                'body' => $ciphertext,
            ]);

            $this->subjectService->afterDocumentUploaded($subject->fresh(['documents', 'client', 'sites']));

            return $document->fresh(['subject']);
        });
    }

    public function completeS3Upload(KycSubject $subject, string $kind, string $s3Key, string $mime, int $byteSize, string $sha256): KycDocument
    {
        return DB::transaction(function () use ($subject, $kind, $s3Key, $mime, $byteSize, $sha256) {
            KycDocument::query()->where('subject_id', (int) $subject->id)->where('kind', $kind)->delete();

            $document = KycDocument::query()->create([
                'subject_id' => (int) $subject->id,
                'kind' => $kind,
                'storage_driver' => 's3',
                'mime' => $mime,
                'byte_size' => $byteSize,
                'sha256' => strtolower($sha256),
                's3_disk' => 's3_kyc',
                's3_key' => $s3Key,
                'uploaded_at' => now(),
            ]);

            $this->subjectService->afterDocumentUploaded($subject->fresh(['documents', 'client', 'sites']));

            return $document->fresh(['subject']);
        });
    }

    public function signedViewUrl(KycDocument $document, User $user): string
    {
        $this->authorizeDocumentAccess($document, $user);
        $this->auditService->record([
            'platform_id' => (int) ($document->subject?->client?->platform_id ?? 0),
            'actor_id' => (int) $user->id,
            'action' => 'kyc.document_viewed',
            'entity_type' => 'kyc_document',
            'entity_id' => (int) $document->id,
            'reason' => 'Viewer requested temporary document URL',
        ]);

        return $this->driverForDocument($document)->signedReadUrl($document, (int) config('kyc.signed_get_ttl_seconds', 60));
    }

    public function delete(KycDocument $document, ?User $actor = null): void
    {
        $subject = $document->subject;
        $before = $document->toArray();
        $this->driverForDocument($document)->delete($document);
        $document->delete();

        if ($subject) {
            $this->auditService->record([
                'platform_id' => (int) ($subject->client?->platform_id ?? 0),
                'actor_id' => $actor?->id,
                'action' => 'kyc.delete_document',
                'entity_type' => 'kyc_subject',
                'entity_id' => (int) $subject->id,
                'before_state' => $before,
                'after_state' => ['deleted' => true],
            ]);
        }
    }

    public function decryptBlob(KycDocument $document): string
    {
        /** @var DbStorage $driver */
        $driver = $this->driverForMode('db');
        $ciphertext = (string) optional($document->blob)->body;

        return $driver->decryptRaw($ciphertext);
    }

    private function authorizeDocumentAccess(KycDocument $document, User $user): void
    {
        $subject = $document->subject()->with('client')->first();
        if (!$subject || !$subject->client) {
            abort(404, 'Document subject not found.');
        }

        if (!in_array($user->role, ['admin', 'sub_admin', 'sales', 'marketing'], true)) {
            abort(403, 'You do not have permission to view KYC documents.');
        }

        if ($user->role === 'sales' && !$this->marketAuthorizationService->userCanAccessPlatform($user, (int) $subject->client->platform_id)) {
            abort(403, 'You do not have access to this document market.');
        }
    }

    private function driverForDocument(KycDocument $document): StorageDriver
    {
        return $this->driverForMode((string) $document->storage_driver);
    }

    private function driverForMode(string $mode): StorageDriver
    {
        return match ($mode) {
            's3' => app(S3Storage::class),
            default => app(DbStorage::class),
        };
    }
}
