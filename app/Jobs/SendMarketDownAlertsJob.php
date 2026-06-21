<?php

namespace App\Jobs;

use App\Models\Platform;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendMarketDownAlertsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $platformId,
        public readonly string $eventKey,
        public readonly string $failureClass,
        public readonly string $errorMessage,
    ) {
        $this->onQueue('alerts');
    }

    public function uniqueId(): string
    {
        return 'market-down-alert:'.$this->eventKey;
    }

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function handle(MarketAuthorizationService $marketAuthorizationService): void
    {
        $platform = Platform::query()
            ->select(['id', 'name', 'country', 'phone_prefix', 'health_status', 'health_error', 'health_down_since_at'])
            ->find($this->platformId);

        if (! $platform) {
            return;
        }

        $recipients = $this->resolveRecipients($marketAuthorizationService, (int) $platform->id);
        if ($recipients->isEmpty()) {
            Log::info('SendMarketDownAlertsJob: no eligible recipients.', [
                'platform_id' => (int) $platform->id,
                'event_key' => $this->eventKey,
            ]);

            return;
        }

        foreach ($recipients as $recipient) {
            SendMarketDownAlertRecipientJob::dispatch(
                (int) $platform->id,
                $this->eventKey,
                [
                    'id' => (int) $recipient->id,
                    'name' => (string) $recipient->name,
                    'role' => (string) $recipient->role,
                    'phone' => $recipient->phone ? (string) $recipient->phone : null,
                ],
                $this->failureClass,
                $this->errorMessage
            )->onQueue('alerts');
        }
    }

    private function resolveRecipients(
        MarketAuthorizationService $marketAuthorizationService,
        int $platformId
    ): EloquentCollection {
        return User::query()
            ->whereIn('role', ['admin', 'sub_admin'])
            ->where('status', 'active')
            ->with('platforms:id')
            ->get(['id', 'name', 'role', 'status', 'phone', 'assigned_market_ids', 'notification_prefs', 'is_ceo'])
            ->filter(function (User $user) use ($marketAuthorizationService, $platformId): bool {
                if (! $marketAuthorizationService->userCanAccessPlatform($user, $platformId)) {
                    return false;
                }

                if (! $user->marketDownSmsEnabled()) {
                    return false;
                }

                $scopedMarkets = $user->marketDownSmsMarketIds();
                if ($scopedMarkets !== null && ! in_array($platformId, $scopedMarkets, true)) {
                    return false;
                }

                return true;
            })
            ->values();
    }
}
