<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Adopts the expiry WordPress reports back, instead of recomputing it locally.
 *
 * WordPress owns when a subscription ends. Both sync-plugin endpoints already say
 * so in their response — /activate returns `escort_expire`, /extend returns
 * `new_expire` — and the CRM used to discard both and derive its own value with
 * `now()->addDays($n)`. That produced three separate defects:
 *
 *   - The plugin rounds to market-local end-of-day; `addDays()` keeps the raw
 *     activation time-of-day in UTC, cutting the final paid day short.
 *   - The plugin stacks onto `max(existing cutoff, now)`; `addDays()` restarts the
 *     clock, silently forfeiting whatever the advertiser had left.
 *   - The legacy Ads-API path used `addMonth()` where the deal used 30 days, so
 *     February subscriptions ran short.
 *
 * Reading the value back makes CRM/WordPress drift impossible by construction
 * rather than something a reconciler has to chase.
 *
 * Two safeguards matter in production. Plugin deploys are manual, so a market may
 * still run a build whose response omits the key — an absent value falls back to
 * the locally computed expiry and behaviour is exactly as before. And a garbled or
 * clock-skewed value must never shorten a subscription, so anything outside a
 * plausible window is rejected in favour of the fallback.
 */
final class WpSubscriptionExpiry
{
    /** Response keys WordPress reports the authoritative expiry under. */
    private const KEYS = ['escort_expire', 'new_expire'];

    /** Reject anything further out than this; a real subscription never is. */
    private const MAX_YEARS_AHEAD = 3;

    /**
     * @param  array<string, mixed>  $response  Decoded /activate or /extend response.
     * @param  Carbon  $fallback  Locally computed expiry, used when WordPress is silent.
     */
    public static function resolve(array $response, Carbon $fallback, array $context = []): Carbon
    {
        $timestamp = self::extract($response);

        if ($timestamp === null) {
            // Older plugin build on this market: nothing reported, keep the local value.
            return $fallback;
        }

        $candidate = Carbon::createFromTimestamp($timestamp);

        if (! self::isPlausible($candidate)) {
            Log::warning('WordPress reported an implausible subscription expiry; keeping the locally computed value.', $context + [
                'wp_expiry_timestamp' => $timestamp,
                'wp_expiry' => $candidate->toDateTimeString(),
                'fallback' => $fallback->toDateTimeString(),
            ]);

            return $fallback;
        }

        return $candidate;
    }

    /**
     * Whether WordPress actually reported an expiry we can use.
     *
     * @param  array<string, mixed>  $response
     */
    public static function reportedBy(array $response): bool
    {
        return self::extract($response) !== null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private static function extract(array $response): ?int
    {
        foreach (self::KEYS as $key) {
            $value = $response[$key] ?? null;

            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    private static function isPlausible(Carbon $candidate): bool
    {
        return $candidate->isFuture()
            && $candidate->lessThanOrEqualTo(Carbon::now()->addYears(self::MAX_YEARS_AHEAD));
    }
}
