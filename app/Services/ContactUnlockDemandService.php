<?php

namespace App\Services;

use App\Models\ContactUnlockEvent;
use App\Models\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Recorded interest is available independently of whether checkout is enabled. */
class ContactUnlockDemandService
{
    public function __construct(private readonly ContactUnlockPricingService $pricing) {}

    /** Intersect the requested country with staff-authorised sites, never widen it. */
    public function countryScope(int|array|null $platformIds, ?string $country): int|array|null
    {
        $country = trim((string) $country);
        if ($country === '') {
            return $platformIds;
        }

        $query = Platform::query()->whereRaw('LOWER(TRIM(country)) = ?', [mb_strtolower($country)]);
        $this->scope($query, $platformIds, 'id');

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function summary(int|array|null $platformIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $platforms = Platform::query()->orderBy('country')->orderBy('name');
        $this->scope($platforms, $platformIds, 'id');
        $platforms = $platforms->get(['id', 'name', 'country', 'is_active']);
        $ids = $platforms->pluck('id')->all();
        $base = ContactUnlockEvent::query()->whereIn('contact_unlock_events.platform_id', $ids);
        $history = (clone $base)->select('platform_id', DB::raw('MAX(occurred_at) as last_event_at'))
            ->groupBy('platform_id')->get()->keyBy('platform_id');
        $attempts = (clone $base)->where('event_type', ContactUnlockEvent::TYPE_CTA_CLICK)
            ->whereBetween('occurred_at', [$from, $to]);
        $counts = (clone $attempts)->select('platform_id', DB::raw('COUNT(*) as attempts'),
            DB::raw("COUNT(DISTINCT NULLIF(session_hash, '')) as sessions"))
            ->groupBy('platform_id')->get()->keyBy('platform_id');

        $checkoutConfigured = $this->pricing->globallyEnabled();
        $enabledIds = $this->pricing->enabledMarketIds();
        $sites = $platforms->map(function (Platform $platform) use ($history, $counts, $checkoutConfigured, $enabledIds): array {
            $count = $counts->get($platform->id);
            $last = $history->get($platform->id)?->last_event_at;

            return [
                'platform_id' => (int) $platform->id,
                'platform_name' => (string) $platform->name,
                'country' => trim((string) $platform->country) ?: 'Unspecified',
                'site_active' => (bool) $platform->is_active,
                'checkout_configured' => $checkoutConfigured && (empty($enabledIds) || in_array((int) $platform->id, $enabledIds, true)),
                'recorded_attempts' => (int) ($count?->attempts ?? 0),
                'site_sessions' => (int) ($count?->sessions ?? 0),
                'coverage' => $last ? 'observed_partial' : 'unavailable',
                'last_event_at' => $last ? CarbonImmutable::parse($last)->toIso8601String() : null,
            ];
        });
        $countries = $sites->groupBy(fn (array $site) => mb_strtolower($site['country']))->map(function ($rows): array {
            return [
                'country' => $rows->first()['country'],
                ...$this->totals($rows->all()),
                'sites' => $rows->values()->all(),
            ];
        })->values()->all();

        // This is the city of the profile being requested, not the visitor's location.
        $profileCitiesQuery = (clone $attempts)->leftJoin('clients', function ($join): void {
            $join->on('clients.id', '=', 'contact_unlock_events.client_id')
                ->on('clients.platform_id', '=', 'contact_unlock_events.platform_id');
        })->leftJoin('platforms', 'platforms.id', '=', 'contact_unlock_events.platform_id')
            ->select('contact_unlock_events.platform_id', 'platforms.name as platform_name', 'platforms.country', 'clients.city', DB::raw('COUNT(*) as attempts'))
            ->groupBy('contact_unlock_events.platform_id', 'platforms.name', 'platforms.country', 'clients.city');
        $locationTotal = DB::query()->fromSub(clone $profileCitiesQuery, 'requested_locations')->count();
        $profileCities = (clone $profileCitiesQuery)->orderByDesc('attempts')->orderBy('contact_unlock_events.platform_id')->orderBy('clients.city')->limit(100)->get()->map(fn ($row) => [
            'platform_id' => (int) $row->platform_id,
            'platform_name' => $row->platform_name,
            'country' => $row->country,
            'city' => trim((string) $row->city) ?: 'Unknown profile city',
            'recorded_attempts' => (int) $row->attempts,
        ])->all();

        return [
            'definition' => 'Attempts to open contact unlock (cta_click), including sites where checkout is disabled. Repeated attempts count separately.',
            'session_definition' => 'Distinct recorded browser sessions per site. Site totals are added across websites; these are not unique people and are not deduplicated across sites.',
            'coverage_note' => 'Observed events are a lower bound. No event history means tracking coverage is unavailable, not proof of zero demand. Historical or continuous collection is not independently verified.',
            'location_definition' => 'Requested locations use the current cached profile city, not a historical event-time city snapshot or visitor origin.',
            'checkout_definition' => 'Checkout configuration reflects the feature settings only; it does not establish provider readiness.',
            'view_definition' => 'eligible_view is a ready checkout drawer, not all profile views; it is not a reliable denominator for attempt conversion.',
            'totals' => $this->totals($sites->all()),
            'countries' => $countries,
            'profile_locations' => $profileCities,
            'profile_location_meta' => ['limit' => 100, 'total' => $locationTotal, 'truncated' => $locationTotal > 100],
            'visitor_locations' => [
                'status' => 'unavailable',
                'reason' => 'Existing contact-unlock events do not retain verified visitor country or city. Profile locations must not be treated as visitor origins.',
            ],
        ];
    }

    private function totals(array $sites): array
    {
        $observed = count(array_filter($sites, fn (array $site) => $site['coverage'] !== 'unavailable'));

        return [
            'recorded_attempts' => array_sum(array_column($sites, 'recorded_attempts')),
            'site_sessions' => array_sum(array_column($sites, 'site_sessions')),
            'sites' => count($sites),
            'sites_with_event_history' => $observed,
            'coverage' => $observed > 0 ? 'observed_partial' : 'unavailable',
        ];
    }

    private function scope(Builder $query, int|array|null $platformIds, string $column): void
    {
        if ($platformIds !== null) {
            $query->whereIn($column, is_int($platformIds) ? [$platformIds] : $platformIds);
        }
    }
}
