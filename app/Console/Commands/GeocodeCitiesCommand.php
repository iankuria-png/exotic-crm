<?php

namespace App\Console\Commands;

use App\Models\CityGeocode;
use App\Models\Client;
use App\Models\Platform;
use App\Services\CityGeocodingService;
use App\Support\CityNormalizer;
use App\Support\CountryCodeResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class GeocodeCitiesCommand extends Command
{
    protected $signature = 'crm:geocode-cities
        {--platform= : Restrict work to one platform ID}
        {--force : Retry resolved and unresolved rows too}
        {--limit= : Override the batch size}
        {--rate= : Override the geocoding requests-per-minute rate}';

    protected $description = 'Resolve canonical client cities into cached geocodes.';

    public function __construct(private readonly CityGeocodingService $geocodingService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $platformId = $this->option('platform');
        $force = (bool) $this->option('force');
        $limit = max(1, (int) ($this->option('limit') ?: config('services.nominatim.batch_limit', 50)));
        $rate = max(1, (int) ($this->option('rate') ?: config('services.nominatim.rate_per_minute', 60)));

        $this->enqueueCities($platformId !== null && $platformId !== '' ? (int) $platformId : null);

        $query = CityGeocode::query()
            ->with('platform:id,country')
            ->when($platformId !== null && $platformId !== '', fn ($builder) => $builder->where('platform_id', (int) $platformId));

        if ($force) {
            $query->whereIn('status', ['pending', 'failed', 'resolved', 'unresolved']);
        } else {
            $query->whereIn('status', ['pending', 'failed']);
        }

        $rows = $query
            ->orderByRaw('last_attempted_at IS NULL DESC')
            ->orderBy('last_attempted_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $summary = [];

        foreach ($rows as $row) {
            $alpha2 = CountryCodeResolver::alpha2($row->platform?->country);
            $result = $this->geocodingService->resolve($row->display_city, $alpha2, $rate);

            $row->attempts = (int) $row->attempts + 1;
            $row->last_attempted_at = now();
            $row->status = (string) ($result['status'] ?? 'failed');
            $row->failure_reason = $result['failure_reason'] ?? null;
            $row->source = 'nominatim';

            if ($row->status === 'resolved') {
                $row->latitude = $result['latitude'];
                $row->longitude = $result['longitude'];
                $row->importance = $result['importance'] ?? null;
                $row->match_type = $result['match_type'] ?? null;
                $row->failure_reason = null;
            } else {
                $row->latitude = null;
                $row->longitude = null;
                $row->importance = null;
                $row->match_type = null;
            }

            $row->save();

            $summary[] = [
                'platform_id' => (int) $row->platform_id,
                'city' => $row->display_city,
                'status' => $row->status,
                'attempts' => (int) $row->attempts,
            ];
        }

        if ($summary !== []) {
            $this->table(['Platform', 'City', 'Status', 'Attempts'], $summary);
        }

        $this->info(sprintf(
            'Enqueued cities and processed %d row(s) at %d request(s)/minute.',
            count($summary),
            $rate
        ));

        return self::SUCCESS;
    }

    private function enqueueCities(?int $platformId): void
    {
        $clients = Client::query()
            ->select(['platform_id', 'city'])
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->when($platformId !== null, fn ($builder) => $builder->where('platform_id', $platformId))
            ->orderBy('platform_id')
            ->get();

        $grouped = $clients
            ->groupBy(fn (Client $client) => sprintf(
                '%d:%s',
                (int) $client->platform_id,
                (string) CityNormalizer::canonicalKey($client->city)
            ));

        $grouped->each(function (Collection $group): void {
            $first = $group->first();
            if (!$first) {
                return;
            }

            $canonicalKey = CityNormalizer::canonicalKey($first->city);
            if ($canonicalKey === null) {
                return;
            }

            $displayCity = $group
                ->pluck('city')
                ->map(fn ($value) => CityNormalizer::normalizeLabel($value, 120))
                ->filter()
                ->countBy()
                ->sortDesc()
                ->keys()
                ->first();

            if (!is_string($displayCity) || $displayCity === '') {
                return;
            }

            CityGeocode::query()->firstOrCreate(
                [
                    'platform_id' => (int) $first->platform_id,
                    'canonical_key' => $canonicalKey,
                ],
                [
                    'display_city' => $displayCity,
                    'status' => 'pending',
                    'source' => 'nominatim',
                ]
            );
        });
    }
}
