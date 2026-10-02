<?php

namespace App\Services;

use App\Models\Client;
use App\Models\VisitorContactUnlock;
use Illuminate\Validation\ValidationException;

class ContactUnlockRevealService
{
    public function __construct(
        private readonly ContactUnlockAccessService $accessService
    ) {}

    public function status(string $publicToken, string $sessionProof, int $targetWpPostId, int $platformId): array
    {
        $unlock = $this->resolveUnlock($publicToken, $sessionProof, $platformId, $targetWpPostId);
        $target = $this->resolveTarget($platformId, $targetWpPostId, $unlock, $sessionProof);
        $targetDenial = $this->accessService->targetDenial($unlock, $target);

        // A paid, active unlock that still cannot open this profile is a refusal worth
        // seeing (typically a lifecycle mismatch between WordPress and the CRM).
        if ($unlock->isActive() && $targetDenial !== null) {
            $this->accessService->recordDenial($platformId, $unlock, $targetDenial, $sessionProof, $target, $targetWpPostId);
        }

        return [
            'status' => (string) $unlock->status,
            'active' => $unlock->isActive() && $targetDenial === null,
            'scope' => (string) $unlock->scope,
            'target_wp_post_id' => (int) $target->wp_post_id,
            'expires_at' => $unlock->expires_at?->toIso8601String(),
            'profile_active' => ! $target->isPubliclyRestricted(),
        ];
    }

    public function reveal(string $publicToken, string $sessionProof, int $targetWpPostId, int $platformId): array
    {
        $unlock = $this->resolveUnlock($publicToken, $sessionProof, $platformId, $targetWpPostId);
        $target = $this->resolveTarget($platformId, $targetWpPostId, $unlock, $sessionProof);

        $denial = $unlock->isActive()
            ? $this->accessService->targetDenial($unlock, $target)
            : ContactUnlockAccessService::DENIED_UNLOCK_INACTIVE;

        if ($denial !== null) {
            $this->accessService->recordDenial($platformId, $unlock, $denial, $sessionProof, $target, $targetWpPostId);

            throw ValidationException::withMessages([
                'unlock' => 'This contact unlock is not active for the requested profile.',
            ]);
        }

        $unlock->forceFill([
            'last_revealed_at' => now(),
            'reveal_count' => (int) $unlock->reveal_count + 1,
        ])->save();

        if (trim((string) $target->phone_normalized) === '') {
            $this->accessService->recordDenial(
                $platformId,
                $unlock,
                ContactUnlockAccessService::DENIED_CONTACT_MISSING,
                $sessionProof,
                $target,
                $targetWpPostId
            );
        }

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
            ],
            'expires_at' => $unlock->expires_at?->toIso8601String(),
        ];
    }

    private function resolveUnlock(string $publicToken, string $sessionProof, int $platformId, int $targetWpPostId): VisitorContactUnlock
    {
        ['unlock' => $unlock, 'denied' => $denied] = $this->accessService->resolve($platformId, $publicToken, $sessionProof);

        if ($denied !== null || ! $unlock) {
            $this->accessService->recordDenial(
                $platformId,
                $unlock,
                $denied ?? ContactUnlockAccessService::DENIED_TOKEN_UNKNOWN,
                $sessionProof,
                $this->findTarget($platformId, $targetWpPostId),
                $targetWpPostId
            );

            throw ValidationException::withMessages([
                'unlock' => 'Unlock session is invalid or expired.',
            ]);
        }

        return $unlock;
    }

    private function resolveTarget(int $platformId, int $wpPostId, VisitorContactUnlock $unlock, string $sessionProof): Client
    {
        $target = $this->findTarget($platformId, $wpPostId);

        if (! $target) {
            $this->accessService->recordDenial(
                $platformId,
                $unlock,
                ContactUnlockAccessService::DENIED_PROFILE_NOT_FOUND,
                $sessionProof,
                null,
                $wpPostId
            );

            throw ValidationException::withMessages([
                'target_wp_post_id' => 'The requested profile was not found for this market.',
            ]);
        }

        return $target;
    }

    private function findTarget(int $platformId, int $wpPostId): ?Client
    {
        return Client::query()
            ->where('platform_id', $platformId)
            ->where('wp_post_id', $wpPostId)
            ->first();
    }
}
