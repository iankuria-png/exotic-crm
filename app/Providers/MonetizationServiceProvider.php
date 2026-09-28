<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\ContentMonetizationSetting;
use App\Models\Deal;
use App\Services\Monetization\SyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class MonetizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $sync = static function (?int $clientId): void {
            if (! $clientId || ! Schema::hasTable('content_monetization_settings')) {
                return;
            }
            $client = Client::find($clientId);
            if (! $client || ! ContentMonetizationSetting::where('platform_id', $client->platform_id)->where('enabled', true)->exists()) {
                return;
            }
            DB::afterCommit(static function () use ($clientId): void {
                if ($client = Client::find($clientId)) {
                    try {
                        app(SyncService::class)->profile($client);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });
        };
        Deal::saved(static function (Deal $deal) use ($sync): void {
            if ($deal->wasChanged(['status', 'expires_at', 'client_id', 'is_free_trial']) || $deal->wasRecentlyCreated) {
                $sync($deal->client_id);
            }
        });
        Deal::deleted(static fn (Deal $deal) => $sync($deal->client_id));
        Client::saved(static function (Client $client) use ($sync): void {
            if (! $client->wasRecentlyCreated && $client->wasChanged(['profile_status', 'escort_expire', 'is_high_risk', 'lifecycle_archived_at', 'closed_at'])) {
                $sync($client->id);
            }
        });
    }
}
