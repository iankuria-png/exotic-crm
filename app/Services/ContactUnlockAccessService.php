<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ContactUnlockEvent;
use App\Models\ContactUnlockSession;
use App\Models\Platform;
use App\Models\VisitorContactUnlock;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Resolves which paid unlock a browser may use, restores access on a new browser
 * from the paying phone number, and records why a reveal was refused.
 */
class ContactUnlockAccessService
{
    public const DENIED_TOKEN_UNKNOWN = 'token_unknown';
    public const DENIED_SESSION_MISMATCH = 'session_mismatch';
    public const DENIED_PROFILE_NOT_FOUND = 'profile_not_found';
    public const DENIED_UNLOCK_INACTIVE = 'unlock_inactive';
    public const DENIED_DIFFERENT_PROFILE = 'different_profile';
    public const DENIED_PROFILE_NOT_RESTRICTED = 'profile_not_restricted';
    public const DENIED_CONTACT_MISSING = 'contact_missing';

    private const RESTORE_GRANT_LIMIT = 10;
    private const SESSION_TOUCH_MINUTES = 60;

    public function __construct(
        private readonly ContactUnlockCheckoutService $checkoutService
    ) {}

    /**
     * @return array{unlock: ?VisitorContactUnlock, denied: ?string}
     */
    public function resolve(int $platformId, string $publicToken, string $sessionProof): array
    {
        $tokenHash = $this->hashToken(trim($publicToken));
        $sessionHash = $this->hashToken(trim($sessionProof));

        $unlock = VisitorContactUnlock::query()
            ->where('platform_id', $platformId)
            ->where('public_token_hash', $tokenHash)
            ->first();

        if ($unlock) {
            return hash_equals((string) $unlock->session_token_hash, $sessionHash)
                ? ['unlock' => $unlock, 'denied' => null]
                : ['unlock' => $unlock, 'denied' => self::DENIED_SESSION_MISMATCH];
        }

        $session = ContactUnlockSession::query()
            ->with('visitorUnlock')
            ->where('platform_id', $platformId)
            ->where('public_token_hash', $tokenHash)
            ->first();

        if (! $session || ! $session->visitorUnlock) {
            return ['unlock' => null, 'denied' => self::DENIED_TOKEN_UNKNOWN];
        }

        if (! hash_equals((string) $session->session_token_hash, $sessionHash)) {
            return ['unlock' => $session->visitorUnlock, 'denied' => self::DENIED_SESSION_MISMATCH];
        }

        // Status polling hits this every few seconds; avoid a write per poll.
        if (! $session->last_used_at || $session->last_used_at->lt(now()->subMinutes(self::SESSION_TOUCH_MINUTES))) {
            $session->forceFill(['last_used_at' => now()])->save();
        }

        return ['unlock' => $session->visitorUnlock, 'denied' => null];
    }

    public function canRevealTarget(VisitorContactUnlock $unlock, Client $target): bool
    {
        return $this->targetDenial($unlock, $target) === null;
    }

    public function targetDenial(VisitorContactUnlock $unlock, Client $target): ?string
    {
        if ((int) $unlock->platform_id !== (int) $target->platform_id) {
            return self::DENIED_DIFFERENT_PROFILE;
        }

        if ((string) $unlock->scope === VisitorContactUnlock::SCOPE_SINGLE_PROFILE) {
            return (int) $unlock->wp_post_id === (int) $target->wp_post_id ? null : self::DENIED_DIFFERENT_PROFILE;
        }

        if ((string) $unlock->scope === VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES) {
            return $target->isPubliclyRestricted() ? null : self::DENIED_PROFILE_NOT_RESTRICTED;
        }

        return self::DENIED_DIFFERENT_PROFILE;
    }

    /**
     * Link every active unlock bought with this phone number to the caller's
     * browser session. Product decision (25 Sep 2026): the paying number alone is
     * enough; attempts are throttled per session, visitor IP and number.
     */
    public function restore(Platform $platform, string $visitorPhone, string $sessionProof, int $targetWpPostId, ?string $visitorIp = null): array
    {
        $normalizedPhone = $this->checkoutService->normalizePhone($visitorPhone, (string) ($platform->phone_prefix ?? ''));
        if ($normalizedPhone === '') {
            throw ValidationException::withMessages(['visitor_phone' => 'Enter the mobile money number you paid with.']);
        }

        $sessionHash = $this->hashToken(trim($sessionProof));
        $phoneHash = $this->hashToken($normalizedPhone);
        $this->throttleRestore($sessionHash, $phoneHash, $visitorIp);

        $unlocks = VisitorContactUnlock::query()
            ->active()
            ->where('platform_id', (int) $platform->id)
            ->where('visitor_phone_hash', $phoneHash)
            ->orderByRaw('CASE WHEN scope = ? THEN 0 ELSE 1 END', [VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES])
            ->orderByDesc('expires_at')
            ->limit(self::RESTORE_GRANT_LIMIT)
            ->get();

        if ($unlocks->isEmpty()) {
            Log::info('contact_unlock.restore_not_found', [
                'platform_id' => (int) $platform->id,
                'phone_hash_prefix' => substr($phoneHash, 0, 12),
            ]);

            throw ValidationException::withMessages([
                'visitor_phone' => 'We couldn’t find active access for this number. Use the exact number you paid with.',
            ]);
        }

        $target = Client::query()
            ->where('platform_id', (int) $platform->id)
            ->where('wp_post_id', $targetWpPostId)
            ->first();

        $grants = $unlocks->map(function (VisitorContactUnlock $unlock) use ($platform, $sessionHash, $target, $targetWpPostId): array {
            $publicToken = $this->newPublicToken();

            ContactUnlockSession::query()->updateOrCreate(
                [
                    'visitor_contact_unlock_id' => (int) $unlock->id,
                    'session_token_hash' => $sessionHash,
                ],
                [
                    'platform_id' => (int) $platform->id,
                    'public_token_hash' => $this->hashToken($publicToken),
                    'source' => ContactUnlockSession::SOURCE_PHONE_RESTORE,
                    'last_used_at' => now(),
                ]
            );

            $this->recordEvent(
                ContactUnlockEvent::TYPE_ACCESS_RESTORED,
                (int) $platform->id,
                $unlock,
                $sessionHash,
                $target,
                $targetWpPostId,
                []
            );

            return [
                'public_token' => $publicToken,
                'scope' => (string) $unlock->scope,
                'wp_post_id' => $unlock->wp_post_id !== null ? (int) $unlock->wp_post_id : null,
                'expires_at' => $unlock->expires_at?->toIso8601String(),
                'covers_target' => $target !== null && $this->canRevealTarget($unlock, $target),
            ];
        })->values();

        return [
            'restored' => true,
            'covers_target' => $grants->contains(fn (array $grant) => $grant['covers_target']),
            'grants' => $grants->all(),
        ];
    }

    /**
     * Record a refused (or empty) reveal so admins can see why a paying visitor is
     * stuck. Deduplicated per session, unlock, profile and reason each hour; never
     * allowed to break the visitor-facing response.
     */
    public function recordDenial(
        int $platformId,
        ?VisitorContactUnlock $unlock,
        string $reason,
        string $sessionProof,
        ?Client $target,
        int $targetWpPostId
    ): void {
        $this->recordEvent(
            ContactUnlockEvent::TYPE_REVEAL_DENIED,
            $platformId,
            $unlock,
            $this->hashToken(trim($sessionProof)),
            $target,
            $targetWpPostId,
            array_filter([
                'reason' => $reason,
                'unlock_status' => $unlock ? (string) $unlock->status : null,
                'unlock_expires_at' => $unlock?->expires_at?->toIso8601String(),
                'target_lifecycle_state' => $target ? (string) $target->lifecycle_state : null,
            ], fn ($value) => $value !== null)
        );

        Log::info('contact_unlock.reveal_denied', [
            'platform_id' => $platformId,
            'unlock_id' => $unlock?->id,
            'reason' => $reason,
            'target_wp_post_id' => $targetWpPostId,
        ]);
    }

    private function recordEvent(
        string $type,
        int $platformId,
        ?VisitorContactUnlock $unlock,
        string $sessionHash,
        ?Client $target,
        int $targetWpPostId,
        array $metadata
    ): void {
        try {
            $eventHash = $this->hashToken(implode('|', [
                $type,
                $platformId,
                $sessionHash,
                (int) ($unlock?->id ?? 0),
                $targetWpPostId,
                (string) ($metadata['reason'] ?? ''),
                now()->format('YmdH'),
            ]));

            ContactUnlockEvent::query()->firstOrCreate(
                ['event_id_hash' => $eventHash],
                [
                    'platform_id' => $platformId,
                    'client_id' => $target?->id,
                    'wp_post_id' => $targetWpPostId > 0 ? $targetWpPostId : null,
                    'visitor_contact_unlock_id' => $unlock?->id,
                    'event_type' => $type,
                    'scope' => $unlock?->scope,
                    'session_hash' => $sessionHash,
                    'visitor_phone_hash' => $unlock?->visitor_phone_hash,
                    'occurred_at' => now(),
                    'metadata_json' => $metadata,
                ]
            );
        } catch (Throwable $exception) {
            Log::warning('contact_unlock.event_record_failed', [
                'event_type' => $type,
                'platform_id' => $platformId,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function throttleRestore(string $sessionHash, string $phoneHash, ?string $visitorIp): void
    {
        $keys = [
            'contact-unlock-restore:session:'.$sessionHash => [5, 900],
            'contact-unlock-restore:phone:'.$phoneHash => [10, 3600],
        ];

        $ip = trim((string) $visitorIp);
        if ($ip !== '') {
            $keys['contact-unlock-restore:ip:'.$this->hashToken($ip)] = [10, 900];
        }

        foreach ($keys as $key => [$maxAttempts]) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                throw ValidationException::withMessages([
                    'visitor_phone' => 'Too many attempts. Please wait a few minutes and try again.',
                ])->status(429);
            }
        }

        foreach ($keys as $key => [, $decaySeconds]) {
            RateLimiter::hit($key, $decaySeconds);
        }
    }

    private function newPublicToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
