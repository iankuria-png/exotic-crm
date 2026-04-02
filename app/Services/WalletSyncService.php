<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Platform;
use Illuminate\Support\Facades\Log;
use Throwable;

class WalletSyncService
{
    public function __construct(
        private readonly BillingModeService $billingModeService,
        private readonly WalletPayloadService $walletPayloadService,
        private readonly WalletService $walletService
    ) {
    }

    public function syncClientBalanceById(int $clientId): array
    {
        $client = Client::query()->with('platform')->find($clientId);
        if (!$client) {
            return [
                'status' => 'skipped',
                'reason' => 'client_missing',
            ];
        }

        return $this->syncClientBalance($client);
    }

    public function syncClientBalance(Client $client): array
    {
        $client = $client->fresh(['platform']) ?? $client->loadMissing('platform');
        $platform = $client->platform;

        if (!$platform || !$this->platformHasWpSync($platform) || (int) ($client->wp_post_id ?? 0) <= 0) {
            return [
                'status' => 'skipped',
                'reason' => 'wp_sync_not_configured',
            ];
        }

        $context = $this->billingModeService->walletContext($platform);
        $summary = $this->walletService->summary(
            $client,
            (int) data_get($context, 'wallet.recent_transactions_limit', 10)
        );
        $syncedAt = now()->toIso8601String();
        $payload = $this->walletPayloadService->balanceSync($client, $summary, $context, $syncedAt);

        try {
            $response = WpSyncService::forPlatform((int) $platform->id)
                ->pushWalletBalance((int) $client->wp_post_id, $payload);

            $client->forceFill([
                'wallet_last_synced_at' => $syncedAt,
            ])->save();

            return [
                'status' => 'synced',
                'payload' => $payload,
                'response' => $response,
            ];
        } catch (Throwable $exception) {
            Log::warning('Wallet balance sync to WordPress failed', [
                'platform_id' => (int) $platform->id,
                'client_id' => (int) $client->id,
                'wp_post_id' => (int) $client->wp_post_id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'payload' => $payload,
                'error' => $exception->getMessage(),
            ];
        }
    }

    public function syncPlatformConfig(Platform $platform, ?Client $anchorClient = null): array
    {
        $platform = $platform->fresh() ?? $platform;
        if (!$this->platformHasWpSync($platform)) {
            return [
                'status' => 'skipped',
                'reason' => 'wp_sync_not_configured',
            ];
        }

        $anchorClient = $this->resolveAnchorClient($platform, $anchorClient);
        if (!$anchorClient) {
            Log::warning('Wallet config sync skipped because no anchor client exists for platform', [
                'platform_id' => (int) $platform->id,
            ]);

            return [
                'status' => 'skipped',
                'reason' => 'anchor_client_missing',
            ];
        }

        $context = $this->billingModeService->walletContext($platform);
        $syncedAt = now()->toIso8601String();
        $payload = $this->walletPayloadService->configSync($platform, $context, $syncedAt);

        try {
            $response = WpSyncService::forPlatform((int) $platform->id)
                ->pushWalletConfig((int) $anchorClient->wp_post_id, $payload);

            return [
                'status' => 'synced',
                'payload' => $payload,
                'response' => $response,
                'anchor_client_id' => (int) $anchorClient->id,
            ];
        } catch (Throwable $exception) {
            Log::warning('Wallet config sync to WordPress failed', [
                'platform_id' => (int) $platform->id,
                'client_id' => (int) $anchorClient->id,
                'wp_post_id' => (int) $anchorClient->wp_post_id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'payload' => $payload,
                'error' => $exception->getMessage(),
                'anchor_client_id' => (int) $anchorClient->id,
            ];
        }
    }

    public function syncAllPlatformConfigs(): array
    {
        return Platform::query()
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Platform $platform) => [
                (int) $platform->id => $this->syncPlatformConfig($platform),
            ])
            ->all();
    }

    public function pushPlatformWalletWpCredentials(Platform $platform, string $environment, array $revealed): array
    {
        $platform = $platform->fresh() ?? $platform;
        if (!$this->platformHasWpSync($platform)) {
            return [
                'status' => 'skipped',
                'reason' => 'wp_sync_not_configured',
            ];
        }

        $context = $this->billingModeService->walletContext($platform);
        $payload = [
            'platform_id' => (int) $platform->id,
            'api_base_url' => (string) data_get($context, "system.billing_domains.{$environment}"),
            'bearer_key' => (string) ($revealed['bearer_key'] ?? ''),
            'hmac_secret' => (string) ($revealed['hmac_secret'] ?? ''),
        ];

        try {
            $response = WpSyncService::forPlatform((int) $platform->id)
                ->pushWalletCredentials($payload);

            return [
                'status' => 'synced',
                'payload' => $payload,
                'response' => $response,
            ];
        } catch (Throwable $exception) {
            Log::warning('Wallet credentials push to WordPress failed', [
                'platform_id' => (int) $platform->id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'payload' => $payload,
                'error' => $exception->getMessage(),
            ];
        }
    }

    public function syncAllPlatformClients(Platform $platform): array
    {
        $clients = Client::query()
            ->where('platform_id', (int) $platform->id)
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '>', 0)
            ->get();

        $results = [];
        foreach ($clients as $client) {
            $results[(int) $client->id] = $this->syncClientBalance($client);
        }

        return [
            'platform_id' => (int) $platform->id,
            'client_count' => $clients->count(),
            'synced_count' => collect($results)->where('status', 'synced')->count(),
            'failed_count' => collect($results)->where('status', 'failed')->count(),
            'skipped_count' => collect($results)->where('status', 'skipped')->count(),
        ];
    }

    private function resolveAnchorClient(Platform $platform, ?Client $anchorClient = null): ?Client
    {
        if ($anchorClient && (int) ($anchorClient->wp_post_id ?? 0) > 0 && (int) $anchorClient->platform_id === (int) $platform->id) {
            return $anchorClient->fresh(['platform']) ?? $anchorClient;
        }

        return Client::query()
            ->with('platform')
            ->where('platform_id', (int) $platform->id)
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '>', 0)
            ->orderBy('id')
            ->first();
    }

    private function platformHasWpSync(Platform $platform): bool
    {
        return trim((string) ($platform->wp_api_url ?? '')) !== ''
            && trim((string) ($platform->wp_api_user ?? '')) !== ''
            && trim((string) ($platform->wp_api_password ?? '')) !== '';
    }
}
