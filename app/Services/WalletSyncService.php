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
        private readonly WalletService $walletService,
        private readonly WalletSettingsService $walletSettingsService
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

    public function syncPlatformState(
        Platform $platform,
        ?Client $anchorClient = null,
        ?int $updatedBy = null
    ): array {
        return [
            'config' => $this->syncPlatformConfig($platform, $anchorClient),
            'credentials' => $this->pushActiveWpCredentials($platform, $updatedBy),
        ];
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

    public function syncAllPlatformStates(?int $updatedBy = null): array
    {
        return Platform::query()
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Platform $platform) => [
                (int) $platform->id => $this->syncPlatformState($platform, null, $updatedBy),
            ])
            ->all();
    }

    public function pushActiveWpCredentials(Platform $platform, ?int $updatedBy = null): array
    {
        $platform = $platform->fresh() ?? $platform;
        if (!$this->platformHasWpSync($platform)) {
            $result = [
                'status' => 'skipped',
                'reason' => 'wp_sync_not_configured',
            ];

            return $this->recordCredentialSyncAttempt($platform, null, $result, $updatedBy);
        }

        $context = $this->billingModeService->walletContext($platform);
        $mode = (string) ($context['mode'] ?? 'disabled');
        if ($mode === 'disabled') {
            $result = [
                'status' => 'skipped',
                'reason' => 'wallet_disabled',
            ];

            return $this->recordCredentialSyncAttempt($platform, null, $result, $updatedBy);
        }

        $environment = (string) ($context['environment'] ?? 'sandbox');
        $credentialPair = $this->walletSettingsService->wpToCrmCredentialPair($platform, $environment);
        $rotation = null;
        $credentialAction = 'pushed_existing';

        if (($credentialPair['bearer_key'] ?? '') === '' || ($credentialPair['hmac_secret'] ?? '') === '') {
            $rotation = $this->walletSettingsService->previewWpCredentialRotation($platform, $environment, 'both');
            $credentialPair = [
                'bearer_key' => (string) ($rotation['revealed']['bearer_key'] ?? ''),
                'hmac_secret' => (string) ($rotation['revealed']['hmac_secret'] ?? ''),
            ];
            $credentialAction = 'generated_and_pushed';
        }

        if (($credentialPair['bearer_key'] ?? '') === '' || ($credentialPair['hmac_secret'] ?? '') === '') {
            $result = [
                'status' => 'failed',
                'reason' => 'wp_credentials_missing',
                'environment' => $environment,
                'credential_action' => $credentialAction,
            ];

            return $this->recordCredentialSyncAttempt($platform, $environment, $result, $updatedBy);
        }

        $payload = $this->walletSettingsService->wpCredentialSyncPayload($platform, $environment, $credentialPair);

        try {
            $response = WpSyncService::forPlatform((int) $platform->id)
                ->pushWalletCredentials($payload);

            $platformWallet = null;
            if ($rotation !== null) {
                $platformWallet = $this->walletSettingsService->persistPlatformCredentialsSnapshot(
                    $platform,
                    $rotation['credentials'],
                    $updatedBy
                );
            }

            $result = [
                'status' => 'synced',
                'environment' => $environment,
                'credential_action' => $credentialAction,
                'response' => $response,
                'platform_wallet' => $platformWallet,
            ];

            return $this->recordCredentialSyncAttempt($platform, $environment, $result, $updatedBy);
        } catch (Throwable $exception) {
            Log::warning('Wallet credential sync to WordPress failed', [
                'platform_id' => (int) $platform->id,
                'environment' => $environment,
                'credential_action' => $credentialAction,
                'error' => $exception->getMessage(),
            ]);

            $result = [
                'status' => 'failed',
                'environment' => $environment,
                'credential_action' => $credentialAction,
                'error' => $exception->getMessage(),
            ];

            return $this->recordCredentialSyncAttempt($platform, $environment, $result, $updatedBy);
        }
    }

    public function rotateWpCredentials(
        Platform $platform,
        string $environment,
        string $credential,
        ?int $updatedBy = null
    ): array {
        $platform = $platform->fresh() ?? $platform;
        $rotation = $this->walletSettingsService->previewWpCredentialRotation($platform, $environment, $credential);
        $context = $this->billingModeService->walletContext($platform);
        $activeEnvironment = (string) ($context['environment'] ?? 'sandbox');
        $mode = (string) ($context['mode'] ?? 'disabled');

        if ($mode !== 'disabled' && $rotation['environment'] === $activeEnvironment) {
            $currentPair = $this->walletSettingsService->wpToCrmCredentialPair($platform, $rotation['environment']);
            $payload = $this->walletSettingsService->wpCredentialSyncPayload($platform, $rotation['environment'], [
                'bearer_key' => (string) ($rotation['revealed']['bearer_key'] ?? ($currentPair['bearer_key'] ?? '')),
                'hmac_secret' => (string) ($rotation['revealed']['hmac_secret'] ?? ($currentPair['hmac_secret'] ?? '')),
            ]);

            try {
                $response = WpSyncService::forPlatform((int) $platform->id)
                    ->pushWalletCredentials($payload);

                $platformWallet = $this->walletSettingsService->persistPlatformCredentialsSnapshot(
                    $platform,
                    $rotation['credentials'],
                    $updatedBy
                );

                $result = [
                    'environment' => $rotation['environment'],
                    'credential' => $rotation['credential'],
                    'revealed' => $rotation['revealed'],
                    'platform_wallet' => $platformWallet,
                    'wp_credentials_sync' => [
                        'status' => 'synced',
                        'environment' => $rotation['environment'],
                        'credential_action' => 'rotated_and_pushed',
                        'response' => $response,
                    ],
                ];

                return $this->recordRotationSyncAttempt($platform, $rotation['environment'], $result, $updatedBy);
            } catch (Throwable $exception) {
                Log::warning('Wallet credential rotation push to WordPress failed', [
                    'platform_id' => (int) $platform->id,
                    'environment' => $rotation['environment'],
                    'error' => $exception->getMessage(),
                ]);

                $result = [
                    'environment' => $rotation['environment'],
                    'credential' => $rotation['credential'],
                    'platform_wallet' => $this->walletSettingsService->currentPlatformConfig($platform, masked: true),
                    'wp_credentials_sync' => [
                        'status' => 'failed',
                        'environment' => $rotation['environment'],
                        'credential_action' => 'rotation_not_persisted',
                        'error' => $exception->getMessage(),
                    ],
                ];

                return $this->recordRotationSyncAttempt($platform, $rotation['environment'], $result, $updatedBy);
            }
        }

        $platformWallet = $this->walletSettingsService->persistPlatformCredentialsSnapshot(
            $platform,
            $rotation['credentials'],
            $updatedBy
        );

        $result = [
            'environment' => $rotation['environment'],
            'credential' => $rotation['credential'],
            'revealed' => $rotation['revealed'],
            'platform_wallet' => $platformWallet,
            'wp_credentials_sync' => [
                'status' => 'skipped',
                'reason' => $mode === 'disabled' ? 'wallet_disabled' : 'environment_not_active',
                'environment' => $rotation['environment'],
                'credential_action' => 'rotation_persisted_pending_push',
            ],
        ];

        return $this->recordRotationSyncAttempt($platform, $rotation['environment'], $result, $updatedBy);
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

    private function recordCredentialSyncAttempt(
        Platform $platform,
        ?string $environment,
        array $result,
        ?int $updatedBy = null
    ): array {
        $environment = $environment
            ?: (string) ($result['environment'] ?? data_get($result, 'wp_credentials_sync.environment', 'sandbox'));

        $this->walletSettingsService->recordWpCredentialSyncAttempt(
            $platform,
            $environment,
            $this->credentialSyncAttemptState($result, data_get($result, 'credential_action')),
            $updatedBy
        );

        return $result;
    }

    private function recordRotationSyncAttempt(
        Platform $platform,
        string $environment,
        array $result,
        ?int $updatedBy = null
    ): array {
        $this->walletSettingsService->recordWpCredentialSyncAttempt(
            $platform,
            $environment,
            $this->credentialSyncAttemptState(
                data_get($result, 'wp_credentials_sync', []),
                data_get($result, 'wp_credentials_sync.credential_action'),
                $result
            ),
            $updatedBy
        );

        return $result;
    }

    private function credentialSyncAttemptState(
        array $result,
        ?string $credentialAction = null,
        array $fullResult = []
    ): array {
        $status = (string) ($result['status'] ?? 'unknown');
        $state = [
            'last_attempt_at' => now()->toIso8601String(),
            'last_status' => $status,
            'last_reason' => $result['reason'] ?? null,
            'last_error' => $result['error'] ?? null,
            'last_credential_action' => $credentialAction,
            'last_result' => array_filter([
                'status' => $status,
                'environment' => $result['environment'] ?? null,
                'reason' => $result['reason'] ?? null,
                'credential_action' => $credentialAction,
                'response' => $result['response'] ?? null,
                'credential' => $fullResult['credential'] ?? null,
            ], static fn ($value) => $value !== null),
        ];

        if ($status === 'synced') {
            $state['last_synced_at'] = now()->toIso8601String();
        }

        return $state;
    }
}
