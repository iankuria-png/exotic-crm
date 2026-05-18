<?php

namespace App\Services\Kyc\KycStorage;

use App\Models\KycDocument;
use App\Models\KycSubject;

interface StorageDriver
{
    public function initiate(KycSubject $subject, string $kind, string $mime, int $bytes, string $sha256, array $context = []): UploadTarget;

    public function complete(KycDocument $document, array $payload = []): void;

    public function signedReadUrl(KycDocument $document, int $ttlSeconds = 60): string;

    public function delete(KycDocument $document): void;
}
