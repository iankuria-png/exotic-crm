<?php

namespace App\Services\SubscriptionAudit;

use Carbon\CarbonImmutable;

/** Exact two-decimal stored-CRM amounts, deterministic handles and safe timestamps. */
class AuditValue
{
    public static function minor(mixed $amount): ?int
    {
        if (is_float($amount)) {
            $amount = number_format($amount, 2, '.', '');
        }
        if (! is_string($amount) && ! is_int($amount)) {
            return null;
        }
        if (! preg_match('/^(-?)(\d{1,13})(?:\.(\d{1,2}))?$/D', trim((string) $amount), $match)) {
            return null;
        }
        $minor = ((int) $match[2] * 100) + (int) str_pad($match[3] ?? '', 2, '0');

        return ($match[1] === '-' ? -1 : 1) * $minor;
    }

    public static function decimal(?int $minor): ?string
    {
        if ($minor === null) {
            return null;
        }

        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function handle(string $type, int|string|null $identity): ?string
    {
        if ($identity === null || $identity === '' || $identity === 0) {
            return null;
        }
        if ($type === 'cli' || $type === 'deal') {
            return app(\App\Services\Mcp\PseudonymService::class)->handle($type === 'cli' ? 'client' : 'deal', (int) $identity);
        }
        if ($type === 'pay') {
            return app(\App\Services\Mcp\OpaqueEntityLocator::class)->payment((int) $identity);
        }
        $length = $type === 'evt' ? 64 : 32;

        return $type.'_'.substr(hash_hmac('sha256', 'subscription-audit:'.$type.':'.$identity, (string) config('app.key')), 0, $length);
    }

    public static function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return is_string($value) ? (json_decode($value, true) ?: []) : [];
    }

    public static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value, 'UTC')->utc()->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
