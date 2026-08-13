<?php

namespace App\Services\Compliance;

use App\Models\Client;
use App\Models\ComplianceEvidenceExport;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Kyc\KycDocumentService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class EvidencePackService
{
    public function __construct(
        private readonly CreatorAgreementService $creatorAgreementService,
        private readonly ContentDeclarationService $contentDeclarationService,
        private readonly KycDocumentService $kycDocumentService,
        private readonly AuditService $auditService,
    ) {}

    public function buildPayload(Client $client, ?User $viewer = null): array
    {
        $client->loadMissing([
            'platform',
            'kycSubject.documents.blob',
            'kycSubject.documents.uploadedBy',
        ]);

        return [
            'client' => [
                'id' => (int) $client->id,
                'name' => $client->name,
                'platform_id' => (int) $client->platform_id,
                'platform_name' => $client->platform?->name ?? $client->platform?->platform_name,
                'wp_user_id' => $client->wp_user_id ? (int) $client->wp_user_id : null,
                'wp_post_id' => $client->wp_post_id ? (int) $client->wp_post_id : null,
                'wp_profile_url' => $client->wp_profile_url,
                'verified' => (bool) $client->verified,
                'verified_source' => $client->verified_source,
            ],
            'creator_agreement' => $this->creatorAgreementService->payloadForClient($client),
            'kyc' => $this->kycPayload($client, $viewer),
            'content_compliance' => $this->contentDeclarationService->payloadForClient($client),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function generate(Client $client, User $requester, string $reason): ComplianceEvidenceExport
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Evidence export reason is required.');
        }

        $payload = $this->buildPayload($client, $requester);
        $timestamp = now()->format('YmdHis');
        $basePath = "compliance/evidence-packs/client-{$client->id}";
        $extension = class_exists(ZipArchive::class) ? 'zip' : 'json';
        $path = "{$basePath}/evidence-pack-{$timestamp}.{$extension}";

        if ($extension === 'zip') {
            $this->writeZipPack($path, $payload, $client);
        } else {
            Storage::disk('local')->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $export = ComplianceEvidenceExport::query()->create([
            'client_id' => (int) $client->id,
            'platform_id' => (int) $client->platform_id,
            'requested_by_user_id' => (int) $requester->id,
            'storage_disk' => 'local',
            'storage_path' => $path,
            'manifest_json' => $payload,
            'reason' => $reason,
            'expires_at' => now()->addHours(48),
        ]);

        $this->auditService->record([
            'platform_id' => (int) $client->platform_id,
            'actor_id' => (int) $requester->id,
            'action' => 'compliance.evidence_pack_generated',
            'entity_type' => 'compliance_evidence_export',
            'entity_id' => (int) $export->id,
            'after_state' => [
                'client_id' => (int) $client->id,
                'storage_path' => $path,
                'reason' => $reason,
            ],
            'reason' => $reason,
        ]);

        return $export;
    }

    private function kycPayload(Client $client, ?User $viewer): array
    {
        $subject = $client->kycSubject;

        if (! $subject) {
            return [
                'status' => 'missing',
                'subject' => null,
                'documents' => [],
            ];
        }

        return [
            'status' => $subject->status,
            'subject' => [
                'id' => (int) $subject->id,
                'verified_at' => optional($subject->verified_at)->toIso8601String(),
                'expires_at' => optional($subject->expires_at)->toIso8601String(),
                'last_reason_user' => $subject->last_reason_user,
                'last_reason_internal' => $subject->last_reason_internal,
            ],
            'documents' => $subject->documents->map(function (KycDocument $document) use ($viewer): array {
                return [
                    'id' => (int) $document->id,
                    'kind' => $document->kind,
                    'mime' => $document->mime,
                    'byte_size' => (int) $document->byte_size,
                    'sha256' => $document->sha256,
                    'storage_driver' => $document->storage_driver,
                    'original_filename' => $document->original_filename,
                    'upload_origin' => $document->upload_origin,
                    'upload_source_channel' => $document->upload_source_channel,
                    'uploaded_by_name' => $document->uploadedBy?->name,
                    'uploaded_at' => optional($document->uploaded_at)->toIso8601String(),
                    'export_note' => $document->storage_driver === 'db'
                        ? 'Included in the evidence pack when generated as ZIP.'
                        : 'Stored externally; temporary access URL may be generated during export.',
                    'temporary_view_url' => $viewer && $document->storage_driver !== 'db'
                        ? $this->kycDocumentService->signedViewUrl($document, $viewer)
                        : null,
                ];
            })->values(),
        ];
    }

    private function writeZipPack(string $path, array $payload, Client $client): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'compliance-pack-');
        if ($temp === false) {
            throw new \RuntimeException('Could not create temporary evidence pack.');
        }

        $zip = new ZipArchive;
        if ($zip->open($temp, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not open evidence pack archive.');
        }

        $zip->addFromString('manifest.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $client->loadMissing('kycSubject.documents.blob');
        foreach ($client->kycSubject?->documents ?? [] as $document) {
            if ($document->storage_driver !== 'db') {
                continue;
            }

            $filename = $this->documentFilename($document);
            $zip->addFromString("kyc/{$filename}", $this->kycDocumentService->decryptBlob($document));
        }

        $zip->close();
        Storage::disk('local')->put($path, file_get_contents($temp));
        @unlink($temp);
    }

    private function documentFilename(KycDocument $document): string
    {
        $name = trim((string) $document->original_filename);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $safeBase = Str::slug(pathinfo($name, PATHINFO_FILENAME) ?: $document->kind);
        $extension = $extension ? '.'.strtolower($extension) : '';

        return "{$document->id}-{$document->kind}-{$safeBase}{$extension}";
    }
}
