<?php

namespace App\Services\Messaging\Sidecar;

use App\Models\WhatsAppSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RestoreTokenService
{
    public function issue(WhatsAppSender $sender): array
    {
        $this->forgetCurrentForSender($sender);

        $token = Str::random(64);
        $ttl = (int) config('services.whatsapp.restore_token_ttl_seconds', 120);
        $expiresAt = now()->addSeconds($ttl);

        Cache::put($this->tokenKey($token), [
            'sender_id' => (int) $sender->id,
            'issued_at' => now()->toISOString(),
        ], $expiresAt);

        Cache::put($this->senderKey($sender), $token, $expiresAt);

        return [
            'sender_id' => (int) $sender->id,
            'restore_token' => $token,
            'expires_at' => $expiresAt->toISOString(),
        ];
    }

    public function consume(string $token, WhatsAppSender $sender): bool
    {
        $key = $this->tokenKey($token);
        $payload = Cache::pull($key);
        Cache::forget($this->senderKey($sender));

        return is_array($payload) && (int) ($payload['sender_id'] ?? 0) === (int) $sender->id;
    }

    private function forgetCurrentForSender(WhatsAppSender $sender): void
    {
        $existing = Cache::pull($this->senderKey($sender));
        if ($existing) {
            Cache::forget($this->tokenKey((string) $existing));
        }
    }

    private function tokenKey(string $token): string
    {
        return 'whatsapp:restore_token:' . hash('sha256', $token);
    }

    private function senderKey(WhatsAppSender $sender): string
    {
        return 'whatsapp:restore_sender:' . $sender->id;
    }
}
