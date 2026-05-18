<?php

namespace App\Services\Kyc\KycStorage;

use App\Models\KycDocument;
use App\Models\KycSubject;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class DbStorage implements StorageDriver
{
    public function initiate(KycSubject $subject, string $kind, string $mime, int $bytes, string $sha256, array $context = []): UploadTarget
    {
        $expiresAt = now()->addSeconds((int) config('kyc.upload_jwt_ttl_seconds', 300));
        $token = JWT::encode([
            'sub' => 'kyc_document_blob',
            'subject_id' => (int) $subject->id,
            'kind' => $kind,
            'mime' => $mime,
            'max_bytes' => $bytes,
            'sha256' => $sha256,
            'exp' => $expiresAt->timestamp,
            'iat' => now()->timestamp,
        ], $this->jwtKey(), 'HS256');

        return new UploadTarget(
            mode: 'db',
            url: url('/api/kyc/uploads/blob'),
            method: 'POST',
            headers: ['Authorization' => 'Bearer ' . $token],
            expiresAt: $expiresAt->toIso8601String()
        );
    }

    public function complete(KycDocument $document, array $payload = []): void
    {
        // DB uploads are completed synchronously by the blob upload endpoint.
    }

    public function signedReadUrl(KycDocument $document, int $ttlSeconds = 60): string
    {
        return URL::temporarySignedRoute(
            'api.crm.kyc.documents.blob',
            now()->addSeconds($ttlSeconds),
            ['document' => $document->id]
        );
    }

    public function delete(KycDocument $document): void
    {
        DB::table('kyc_document_blobs')->where('document_id', (int) $document->id)->delete();
    }

    public function encryptRaw(string $contents): string
    {
        return Crypt::encryptString(base64_encode($contents));
    }

    public function decryptRaw(string $ciphertext): string
    {
        return base64_decode(Crypt::decryptString($ciphertext), true) ?: '';
    }

    private function jwtKey(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            return base64_decode(substr($key, 7), true) ?: $key;
        }

        return $key;
    }
}
