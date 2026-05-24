<?php

namespace App\Services\Messaging;

use App\Models\WhatsAppProviderProfile;

interface WhatsAppEngineInterface
{
    public function send(SendRequest $request): SendResult;

    public function verifyInbound(array $payload, string $signature): ?NormalizedInbound;

    public function healthCheck(WhatsAppProviderProfile $profile): array;

    public function id(): string;
}
