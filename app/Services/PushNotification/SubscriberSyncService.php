<?php

namespace App\Services\PushNotification;

use App\Models\Platform;
use App\Models\PushSubscriberSnapshot;
use Carbon\Carbon;

class SubscriberSyncService
{
    public function __construct(
        private readonly PushProviderService $pushProviderService,
    ) {
    }

    /**
     * @return array<int, array{platform_id:int,provider:string,total:int,active:int,snapshot_date:string}>
     */
    public function syncAllPlatforms(): array
    {
        $summary = [];
        $platforms = Platform::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($platforms as $platform) {
            $result = $this->syncPlatform($platform);
            if ($result) {
                $summary[] = $result;
            }
        }

        return $summary;
    }

    /**
     * @return array{platform_id:int,provider:string,total:int,active:int,snapshot_date:string}|null
     */
    public function syncPlatform(Platform $platform): ?array
    {
        $counts = $this->pushProviderService->getSubscriberCountForPlatform((int) $platform->id);
        if (!$counts) {
            return null;
        }

        $provider = (string) ($counts['provider'] ?? 'unknown');
        $snapshotDate = Carbon::today()->toDateString();

        PushSubscriberSnapshot::query()->updateOrCreate(
            [
                'platform_id' => (int) $platform->id,
                'provider' => $provider,
                'snapshot_date' => $snapshotDate,
            ],
            [
                'total_subscribers' => (int) ($counts['total'] ?? 0),
                'active_subscribers' => (int) ($counts['active'] ?? 0),
                'raw_response' => $counts,
            ]
        );

        return [
            'platform_id' => (int) $platform->id,
            'provider' => $provider,
            'total' => (int) ($counts['total'] ?? 0),
            'active' => (int) ($counts['active'] ?? 0),
            'snapshot_date' => $snapshotDate,
        ];
    }

    /**
     * @return array<int, array{snapshot_date:string,provider:string,total_subscribers:int,active_subscribers:int}>
     */
    public function getHistory(int $platformId, int $days = 30): array
    {
        $days = max(1, min($days, 365));
        $from = Carbon::today()->subDays($days - 1)->toDateString();

        return PushSubscriberSnapshot::query()
            ->where('platform_id', $platformId)
            ->where('snapshot_date', '>=', $from)
            ->orderBy('snapshot_date')
            ->orderBy('provider')
            ->get([
                'snapshot_date',
                'provider',
                'total_subscribers',
                'active_subscribers',
            ])
            ->map(fn(PushSubscriberSnapshot $snapshot) => [
                'snapshot_date' => optional($snapshot->snapshot_date)->toDateString() ?: (string) $snapshot->getRawOriginal('snapshot_date'),
                'provider' => (string) $snapshot->provider,
                'total_subscribers' => (int) $snapshot->total_subscribers,
                'active_subscribers' => (int) $snapshot->active_subscribers,
            ])
            ->all();
    }
}
