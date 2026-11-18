<?php

namespace App\Services\Collections;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Paid-subscription timing for candidate profiles at the moment a payment was made.
 * Advisory matching evidence only: it orders and guards suggestions, it never selects a profile.
 */
class CollectionSubscriptionState
{
    public const DUE_AHEAD_DAYS = 3;

    public const DORMANT_AFTER_DAYS = 60;

    public const NEW_PROFILE_DAYS = 30;

    /** @return array<int, array{state: string, expires_at: ?string, last_amount_minor: ?int, paid_deals: int, days_from_expiry: ?float}> */
    public function forClients(array $clientIds, ?CarbonInterface $at): array
    {
        $clientIds = array_values(array_unique(array_map('intval', array_filter($clientIds))));
        $at = Carbon::instance($at ?? now());
        $out = array_fill_keys($clientIds, ['state' => 'none', 'expires_at' => null, 'last_amount_minor' => null, 'paid_deals' => 0, 'days_from_expiry' => null]);
        if (! $clientIds) {
            return $out;
        }
        $rows = DB::table('deals')->whereIn('client_id', $clientIds)->whereNotNull('activated_at')
            ->where('activated_at', '<', $at->copy()->subMinutes(2))
            ->where(fn ($q) => $q->whereNull('is_free_trial')->orWhere('is_free_trial', false))
            ->where('amount', '>', 0)->whereNotIn('status', ['cancelled', 'failed', 'reversed'])
            ->orderBy('activated_at')->orderBy('id')
            ->get(['client_id', 'activated_at', 'expires_at', 'amount']);
        // A profile that never paid is "new" while it is recent: a first payment from its own phone is expected.
        $unpaid = array_diff($clientIds, $rows->pluck('client_id')->map(fn ($id) => (int) $id)->all());
        if ($unpaid) {
            foreach (DB::table('clients')->whereIn('id', $unpaid)->get(['id', 'created_at']) as $client) {
                if ($client->created_at && Carbon::parse($client->created_at)->gte($at->copy()->subDays(self::NEW_PROFILE_DAYS))) {
                    $out[(int) $client->id]['state'] = 'new';
                }
            }
        }
        foreach ($rows->groupBy('client_id') as $clientId => $deals) {
            $latestEnd = $deals->map(fn ($d) => $d->expires_at ?? $d->activated_at)->max();
            $last = $deals->last();
            $days = round(($at->getTimestamp() - Carbon::parse($latestEnd)->getTimestamp()) / 86400, 2);
            $out[(int) $clientId] = ['state' => $this->classify($days), 'expires_at' => Carbon::parse($latestEnd)->toIso8601String(),
                'last_amount_minor' => (int) round((float) $last->amount * 100), 'paid_deals' => $deals->count(), 'days_from_expiry' => $days];
        }

        return $out;
    }

    /** Positive days = lapsed before the payment; negative = still active at payment time. */
    public function classify(float $daysFromExpiry): string
    {
        return match (true) {
            $daysFromExpiry < -self::DUE_AHEAD_DAYS => 'active',
            $daysFromExpiry <= self::DUE_AHEAD_DAYS => 'due',
            $daysFromExpiry <= self::DORMANT_AFTER_DAYS => 'lapsed',
            default => 'dormant',
        };
    }

    /** Ordering for staff review: due first, recently lapsed, new, still active, then no recent paid service. */
    public static function rank(?array $state): int
    {
        return ['due' => 0, 'lapsed' => 1, 'new' => 2, 'active' => 3, 'dormant' => 4, 'none' => 5][$state['state'] ?? 'none'] ?? 5;
    }

    /** Due now or lapsed within thirty days: the profile a renewing payer most likely means. */
    public static function renewing(?array $state): bool
    {
        return ($state['state'] ?? null) === 'due' || (($state['state'] ?? null) === 'lapsed' && ($state['days_from_expiry'] ?? 999) <= 30);
    }

    public static function dormant(?array $state): bool
    {
        // "none" = an older profile that never had paid service; "new" profiles are excluded on purpose.
        return in_array($state['state'] ?? 'none', ['dormant', 'none'], true);
    }
}
