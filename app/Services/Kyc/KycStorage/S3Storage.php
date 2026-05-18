<?php

namespace App\Services\Kyc\KycStorage;

use App\Models\KycDocument;
use App\Models\KycSubject;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class S3Storage implements StorageDriver
{
    public function initiate(KycSubject $subject, string $kind, string $mime, int $bytes, string $sha256, array $context = []): UploadTarget
    {
        $disk = Storage::disk('s3_kyc');
        /** @var mixed $client */
        $client = method_exists($disk, 'getClient') ? $disk->getClient() : null;
        $bucket = config('filesystems.disks.s3_kyc.bucket');
        $key = sprintf('kyc/%d/%s-%s%s', (int) $subject->id, $kind, Str::uuid(), $this->extensionForMime($mime));
        $expiresAt = now()->addSeconds((int) config('kyc.signed_put_ttl_seconds', 300));

        if ($client instanceof S3Client && $bucket) {
            $command = $client->getCommand('PutObject', [
                'Bucket' => $bucket,
                'Key' => $key,
                'ContentType' => $mime,
                'Metadata' => ['sha256' => $sha256],
            ]);
            $request = $client->createPresignedRequest($command, $expiresAt);
            $url = (string) $request->getUri();
        } else {
            $url = rtrim((string) config('filesystems.disks.s3_kyc.url', ''), '/') . '/' . ltrim($key, '/');
        }

        return new UploadTarget(
            mode: 's3',
            url: $url,
            method: 'PUT',
            headers: [
                'Content-Type' => $mime,
                'x-amz-meta-sha256' => $sha256,
            ],
            expiresAt: $expiresAt->toIso8601String(),
            meta: ['s3_key' => $key]
        );
    }

    public function complete(KycDocument $document, array $payload = []): void
    {
        // Verified by HeadObject before row creation in service/controller.
    }

    public function signedReadUrl(KycDocument $document, int $ttlSeconds = 60): string
    {
        return Storage::disk($document->s3_disk ?: 's3_kyc')->temporaryUrl(
            (string) $document->s3_key,
            now()->addSeconds($ttlSeconds),
            ['ResponseContentType' => (string) $document->mime]
        );
    }

    public function delete(KycDocument $document): void
    {
        if ($document->s3_key) {
            Storage::disk($document->s3_disk ?: 's3_kyc')->delete($document->s3_key);
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
