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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class KycDocumentService
{
    private const REVIEWER_UPLOAD_ROLES = ['admin', 'sub_admin', 'sales'];
    private const STAFF_UPLOAD_CHANNELS = ['whatsapp', 'support_chat', 'email', 'manual_assisted'];
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public function __construct(
        private readonly KycSettingsService $settingsService,
        private readonly KycSubjectService $subjectService,
        private readonly AuditService $auditService,
        private readonly MarketAuthorizationService $marketAuthorizationService,
    ) {
    }

    public function initiateUpload(KycSubject $subject, string $kind, string $mime, int $byteSize, string $sha256, array $context = []): UploadTarget
    {
        $this->guardDocumentKind($kind);
        $this->guardMimeType($mime);

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

        $this->guardDocumentKind($kind);
        $this->guardMimeType($mime);

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
            $this->purgeExistingDocuments($subject, $kind);

            $document = KycDocument::query()->create([
                'subject_id' => (int) $subject->id,
                'kind' => $kind,
                'storage_driver' => 'db',
                'mime' => $mime,
                'byte_size' => strlen($contents),
                'sha256' => $actualSha,
                'original_filename' => $file->getClientOriginalName(),
                'upload_origin' => 'advertiser_wp',
                'upload_source_channel' => null,
                'upload_note' => null,
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
        $this->guardDocumentKind($kind);
        $this->guardMimeType($mime);

        return DB::transaction(function () use ($subject, $kind, $s3Key, $mime, $byteSize, $sha256) {
            $this->purgeExistingDocuments($subject, $kind);

            $document = KycDocument::query()->create([
                'subject_id' => (int) $subject->id,
                'kind' => $kind,
                'storage_driver' => 's3',
                'mime' => $mime,
                'byte_size' => $byteSize,
                'sha256' => strtolower($sha256),
                'original_filename' => basename($s3Key),
                'upload_origin' => 'advertiser_wp',
                'upload_source_channel' => null,
                'upload_note' => null,
                's3_disk' => 's3_kyc',
                's3_key' => $s3Key,
                'uploaded_at' => now(),
            ]);

            $this->subjectService->afterDocumentUploaded($subject->fresh(['documents', 'client', 'sites']));

            return $document->fresh(['subject']);
        });
    }

    public function storeStaffUpload(KycSubject $subject, UploadedFile $file, string $kind, User $actor, string $sourceChannel, ?string $note = null): KycDocument
    {
        $this->marketAuthorizationService->ensureRole($actor, self::REVIEWER_UPLOAD_ROLES, 'Only admin, sub-admin, or sales users can upload KYC documents from CRM.');
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($actor, (int) ($subject->client?->platform_id ?? 0));
        $this->guardDocumentKind($kind);
        $this->guardSourceChannel($sourceChannel);

        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            throw new InvalidArgumentException('Unable to read uploaded file.');
        }

        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
        $this->guardMimeType($mime);

        $byteSize = strlen($contents);
        if ($byteSize <= 0 || $byteSize > $this->settingsService->maxDocBytes()) {
            throw new InvalidArgumentException('File is too large or empty.');
        }

        $sha256 = hash('sha256', $contents);
        $note = trim((string) $note) ?: null;
        $mode = $this->settingsService->activeStorageDriver();

        if ($mode === 's3') {
            return $this->storeStaffUploadToS3($subject, $file, $actor, $kind, $sourceChannel, $note, $mime, $byteSize, $sha256, $contents);
        }

        return $this->storeStaffUploadToDb($subject, $file, $actor, $kind, $sourceChannel, $note, $mime, $byteSize, $sha256, $contents);
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

    public function allowedDocumentKinds(): array
    {
        return array_values(array_unique(array_merge(
            ['id_front', 'id_back', 'selfie'],
            $this->settingsService->requiredDocumentKinds(),
        )));
    }

    public function allowedStaffUploadChannels(): array
    {
        return self::STAFF_UPLOAD_CHANNELS;
    }

    public function allowedMimeTypes(): array
    {
        return self::ALLOWED_MIME_TYPES;
    }

    private function storeStaffUploadToDb(KycSubject $subject, UploadedFile $file, User $actor, string $kind, string $sourceChannel, ?string $note, string $mime, int $byteSize, string $sha256, string $contents): KycDocument
    {
        /** @var DbStorage $driver */
        $driver = $this->driverForMode('db');
        $ciphertext = $driver->encryptRaw($contents);

        return DB::transaction(function () use ($subject, $file, $actor, $kind, $sourceChannel, $note, $mime, $byteSize, $sha256, $ciphertext) {
            $before = $subject->toArray();
            $this->purgeExistingDocuments($subject, $kind);

            $document = KycDocument::query()->create([
                'subject_id' => (int) $subject->id,
                'uploaded_by_user_id' => (int) $actor->id,
                'kind' => $kind,
                'storage_driver' => 'db',
                'mime' => $mime,
                'byte_size' => $byteSize,
                'sha256' => $sha256,
                'original_filename' => $file->getClientOriginalName(),
                'upload_origin' => 'crm_staff',
                'upload_source_channel' => $sourceChannel,
                'upload_note' => $note,
                'uploaded_at' => now(),
            ]);

            KycDocumentBlob::query()->create([
                'document_id' => (int) $document->id,
                'body' => $ciphertext,
            ]);

            $subjectAfter = $this->subjectService->afterDocumentUploaded($subject->fresh(['documents', 'client', 'sites']));
            $this->recordStaffUploadAudit($document->fresh(['subject.client', 'uploadedBy']), $actor, $sourceChannel, $note, $before, $subjectAfter->toArray());

            return $document->fresh(['subject.client', 'subject.sites', 'blob', 'uploadedBy']);
        });
    }

    private function storeStaffUploadToS3(KycSubject $subject, UploadedFile $file, User $actor, string $kind, string $sourceChannel, ?string $note, string $mime, int $byteSize, string $sha256, string $contents): KycDocument
    {
        $key = sprintf(
            'kyc/%d/staff/%s-%s%s',
            (int) $subject->id,
            $kind,
            Str::uuid(),
            $this->extensionForMime($mime)
        );

        Storage::disk('s3_kyc')->put($key, $contents);

        try {
            return DB::transaction(function () use ($subject, $file, $actor, $kind, $sourceChannel, $note, $mime, $byteSize, $sha256, $key) {
                $before = $subject->toArray();
                $this->purgeExistingDocuments($subject, $kind);

                $document = KycDocument::query()->create([
                    'subject_id' => (int) $subject->id,
                    'uploaded_by_user_id' => (int) $actor->id,
                    'kind' => $kind,
                    'storage_driver' => 's3',
                    'mime' => $mime,
                    'byte_size' => $byteSize,
                    'sha256' => $sha256,
                    'original_filename' => $file->getClientOriginalName(),
                    'upload_origin' => 'crm_staff',
                    'upload_source_channel' => $sourceChannel,
                    'upload_note' => $note,
                    's3_disk' => 's3_kyc',
                    's3_key' => $key,
                    'uploaded_at' => now(),
                ]);

                $subjectAfter = $this->subjectService->afterDocumentUploaded($subject->fresh(['documents', 'client', 'sites']));
                $this->recordStaffUploadAudit($document->fresh(['subject.client', 'uploadedBy']), $actor, $sourceChannel, $note, $before, $subjectAfter->toArray());

                return $document->fresh(['subject.client', 'subject.sites', 'uploadedBy']);
            });
        } catch (\Throwable $exception) {
            Storage::disk('s3_kyc')->delete($key);
            throw $exception;
        }
    }

    private function recordStaffUploadAudit(KycDocument $document, User $actor, string $sourceChannel, ?string $note, array $beforeSubject, array $afterSubject): void
    {
        $this->auditService->record([
            'platform_id' => (int) ($document->subject?->client?->platform_id ?? 0),
            'actor_id' => (int) $actor->id,
            'action' => 'kyc.document_uploaded_by_staff',
            'entity_type' => 'kyc_document',
            'entity_id' => (int) $document->id,
            'before_state' => [
                'subject' => $beforeSubject,
            ],
            'after_state' => [
                'subject' => $afterSubject,
                'kind' => $document->kind,
                'mime' => $document->mime,
                'storage_driver' => $document->storage_driver,
                'upload_origin' => $document->upload_origin,
                'upload_source_channel' => $sourceChannel,
                'uploaded_by_user_id' => (int) $actor->id,
                'uploaded_by_name' => $actor->name,
                'upload_note' => $note,
            ],
            'reason' => $note ?: 'Staff-assisted KYC upload recorded.',
        ]);
    }

    private function purgeExistingDocuments(KycSubject $subject, string $kind): void
    {
        $existingDocuments = KycDocument::query()
            ->where('subject_id', (int) $subject->id)
            ->where('kind', $kind)
            ->get();

        foreach ($existingDocuments as $existingDocument) {
            $this->driverForDocument($existingDocument)->delete($existingDocument);
            $existingDocument->delete();
        }
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

    private function guardDocumentKind(string $kind): void
    {
        if (!in_array($kind, $this->allowedDocumentKinds(), true)) {
            throw new InvalidArgumentException('Unsupported KYC document type.');
        }
    }

    private function guardSourceChannel(string $sourceChannel): void
    {
        if (!in_array($sourceChannel, self::STAFF_UPLOAD_CHANNELS, true)) {
            throw new InvalidArgumentException('Unsupported staff upload source channel.');
        }
    }

    private function guardMimeType(string $mime): void
    {
        if (!in_array(strtolower($mime), self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported KYC document format.');
        }
    }

    private function extensionForMime(string $mime): string
    {
        return match (strtolower($mime)) {
            'image/jpeg', 'image/jpg' => '.jpg',
            'image/png' => '.png',
            'application/pdf' => '.pdf',
            'image/webp' => '.webp',
            default => '',
        };
    }
}
