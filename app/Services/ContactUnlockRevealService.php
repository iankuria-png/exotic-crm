<?php

namespace App\Services;

use App\Models\Client;
use App\Models\VisitorContactUnlock;
use Illuminate\Validation\ValidationException;

class ContactUnlockRevealService
{
    public function status(string $publicToken, string $sessionProof, int $targetWpPostId, int $platformId): array
    {
        $unlock = $this->resolveUnlock($publicToken, $sessionProof, $platformId);
        $target = $this->resolveTarget($platformId, $targetWpPostId);

        return [
            'status' => (string) $unlock->status,
            'active' => $unlock->isActive() && $this->canRevealTarget($unlock, $target),
            'scope' => (string) $unlock->scope,
            'target_wp_post_id' => (int) $target->wp_post_id,
            'expires_at' => $unlock->expires_at?->toIso8601String(),
            'profile_active' => ! $target->isPubliclyRestricted(),
        ];
    }

    public function reveal(string $publicToken, string $sessionProof, int $targetWpPostId, int $platformId): array
    {
        $unlock = $this->resolveUnlock($publicToken, $sessionProof, $platformId);
        $target = $this->resolveTarget($platformId, $targetWpPostId);

        if (! $unlock->isActive() || ! $this->canRevealTarget($unlock, $target)) {
            throw ValidationException::withMessages([
                'unlock' => 'This contact unlock is not active for the requested profile.',
            ]);
        }

        $unlock->forceFill([
            'last_revealed_at' => now(),
            'reveal_count' => (int) $unlock->reveal_count + 1,
        ])->save();

        return [
            'status' => 'unlocked',
            'scope' => (string) $unlock->scope,
            'target_wp_post_id' => (int) $target->wp_post_id,
            'profile' => [
                'id' => (int) $target->id,
                'name' => (string) $target->name,
                'url' => (string) $target->wp_profile_permalink,
            ],
            'contact' => [
                'phone' => (string) $target->phone_normalized,
                'whatsapp' => (string) $target->phone_normalized,
                'email' => (string) $target->email,
            ],
            'expires_at' => $unlock->expires_at?->toIso8601String(),
        ];
    }

    private function resolveUnlock(string $publicToken, string $sessionProof, int $platformId): VisitorContactUnlock
    {
        $unlock = VisitorContactUnlock::query()
            ->where('platform_id', $platformId)
            ->where('public_token_hash', $this->hashToken($publicToken))
            ->first();

        if (! $unlock || ! hash_equals((string) $unlock->session_token_hash, $this->hashToken($sessionProof))) {
            throw ValidationException::withMessages([
                'unlock' => 'Unlock session is invalid or expired.',
            ]);
        }

        return $unlock;
    }

    private function resolveTarget(int $platformId, int $wpPostId): Client
    {
        $target = Client::query()
            ->where('platform_id', $platformId)
            ->where('wp_post_id', $wpPostId)
            ->first();

        if (! $target) {
            throw ValidationException::withMessages([
                'target_wp_post_id' => 'The requested profile was not found for this market.',
            ]);
        }

        return $target;
    }

    private function canRevealTarget(VisitorContactUnlock $unlock, Client $target): bool
    {
        if ((int) $unlock->platform_id !== (int) $target->platform_id) {
            return false;
        }

        if ((string) $unlock->scope === VisitorContactUnlock::SCOPE_SINGLE_PROFILE) {
            return (int) $unlock->wp_post_id === (int) $target->wp_post_id;
        }

        if ((string) $unlock->scope === VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES) {
            return $target->isPubliclyRestricted();
        }

        return false;
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
