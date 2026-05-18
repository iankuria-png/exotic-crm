<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Platform;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class WpSyncService
{
    private string $baseUrl;
    private string $authHeader;
    private int $platformId;
    private int $defaultTimeout;
    private int $mediaUploadTimeout;

    public function __construct(Platform $platform)
    {
        $this->platformId = (int) $platform->id;
        $this->baseUrl = rtrim($platform->wp_api_url, '/');
        $this->authHeader = 'Basic ' . base64_encode(
            $platform->wp_api_user . ':' . $platform->wp_api_password
        );
        $isRemoteEndpoint = $this->isRemoteEndpoint($this->baseUrl);
        $this->defaultTimeout = $isRemoteEndpoint ? 60 : 30;
        $this->mediaUploadTimeout = $isRemoteEndpoint ? 120 : 60;
    }

    public static function forPlatform(int $platformId): self
    {
        $platform = Platform::findOrFail($platformId);
        return new self($platform);
    }

    /**
     * Fetch paginated client profiles from WordPress
     */
    public function getClients(int $page = 1, int $perPage = 100, ?string $modifiedAfter = null): array
    {
        $params = [
            'per_page' => $perPage,
            'page'     => $page,
        ];

        if ($modifiedAfter) {
            $params['modified_after'] = $modifiedAfter;
        }

        return $this->get('/clients', $params);
    }

    /**
     * Fetch a single client profile
     */
    public function getClient(int $postId): array
    {
        return $this->get("/clients/{$postId}");
    }

    /**
     * Fetch full client profile payload from WordPress.
     */
    public function getClientProfile(int $postId): array
    {
        return $this->get("/clients/{$postId}");
    }

    public function probeClientSyncMeta(): array
    {
        $response = $this->getResponse('/sync/meta');
        $status = $response->status();

        if ($status === 404) {
            return [
                'status' => 'legacy_not_found',
                'http_status' => 404,
                'meta' => null,
            ];
        }

        if (in_array($status, [401, 403], true)) {
            throw new RuntimeException('WordPress sync capability probe failed authentication.');
        }

        if ($status === 405 || $response->serverError()) {
            throw new RuntimeException(sprintf('WordPress sync capability probe failed with HTTP %d.', $status));
        }

        if ($response->failed()) {
            $this->logFailedResponse($response, 'GET', '/sync/meta');
            $response->throw();
        }

        $json = $response->json();
        if (!is_array($json) || !array_key_exists('supports_cursor_sync', $json)) {
            throw new RuntimeException('WordPress sync capability probe returned a malformed response.');
        }

        return [
            'status' => !empty($json['supports_cursor_sync']) ? 'v2' : 'legacy',
            'http_status' => $status,
            'meta' => $json,
        ];
    }

    public function getCursorClients(array $params = []): array
    {
        $params = array_filter(
            $params,
            fn ($value) => $value !== null && $value !== ''
        );

        return $this->get('/clients/sync', $params);
    }

    public function getClientTombstones(array $params = []): array
    {
        $params = array_filter(
            $params,
            fn ($value) => $value !== null && $value !== ''
        );

        return $this->get('/clients/tombstones', $params);
    }

    /**
     * Fetch profile analytics for a single WordPress profile.
     */
    public function getAnalytics(int $postId, ?string $from = null, ?string $to = null): array
    {
        $params = array_filter([
            'from' => $from,
            'to' => $to,
        ], fn ($value) => $value !== null && $value !== '');

        return $this->get("/analytics/{$postId}", $params);
    }

    /**
     * Fetch paginated analytics rankings for one WordPress platform.
     */
    public function getAnalyticsRankings(array $params = []): array
    {
        $params = array_filter(
            $params,
            fn ($value) => $value !== null && $value !== ''
        );

        return $this->get('/analytics/rankings', $params);
    }

    /**
     * Update editable profile fields on WordPress.
     */
    public function updateClientProfile(int $postId, array $fields): array
    {
        return $this->post("/clients/{$postId}/update", [
            'fields' => $fields,
        ]);
    }

    public function pushKycSubjectStatus(int $postId, array $payload): array
    {
        $path = "/wp-json/exotic-kyc/v1/subjects/{$postId}/status";
        $response = Http::withHeaders($this->headers())
            ->timeout($this->defaultTimeout)
            ->post($this->apiRoot() . $path, $payload);

        return $this->decodeResponse($response, 'POST', $path);
    }

    /**
     * Generate a short-lived client session link for WordPress impersonation.
     */
    public function createClientSessionLink(int $postId, array $payload = []): array
    {
        return $this->post("/clients/{$postId}/session-link", array_filter([
            'target' => $payload['target'] ?? null,
            'issued_by' => $payload['issued_by'] ?? null,
            'reason' => $payload['reason'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * Activate a client profile
     */
    public function activateClient(int $postId, string $productType, int $durationDays, ?int $crmDealId = null): array
    {
        $body = [
            'product_type'  => $productType,
            'duration_days' => $durationDays,
        ];

        if ($crmDealId) {
            $body['crm_deal_id'] = $crmDealId;
        }

        return $this->post("/clients/{$postId}/activate", $body);
    }

    /**
     * Push wallet balance state to WordPress for one client.
     */
    public function pushWalletBalance(int $postId, array $payload): array
    {
        return $this->post("/clients/{$postId}/wallet-balance", $payload);
    }

    /**
     * Push wallet config state to WordPress for one platform/site.
     */
    public function pushWalletConfig(int $postId, array $payload): array
    {
        return $this->post("/clients/{$postId}/wallet-config", $payload);
    }

    /**
     * Push wallet credentials state to WordPress for the wallet AJAX proxy.
     */
    public function pushWalletCredentials(array $payload): array
    {
        return $this->post('/wallet-credentials', $payload);
    }

    /**
     * Deactivate a client profile
     */
    public function deactivateClient(int $postId): array
    {
        return $this->post("/clients/{$postId}/deactivate");
    }

    public function deleteClient(int $postId): array
    {
        return $this->delete("/clients/{$postId}/delete");
    }

    /**
     * Extend a client profile
     */
    public function extendClient(int $postId, int $additionalDays): array
    {
        return $this->post("/clients/{$postId}/extend", [
            'additional_days' => $additionalDays,
        ]);
    }

    /**
     * List all tours for a client profile.
     */
    public function getTours(int $postId): array
    {
        return $this->get("/clients/{$postId}/tours");
    }

    /**
     * Add a tour to a client profile (admin-created, published immediately).
     */
    public function addTour(int $postId, array $data): array
    {
        return $this->post("/clients/{$postId}/tours", $data);
    }

    /**
     * Delete a tour from a client profile.
     */
    public function deleteTour(int $postId, int $tourId): array
    {
        return $this->delete("/clients/{$postId}/tours/{$tourId}");
    }

    /**
     * Get profiles expiring within N days
     */
    public function getExpiring(int $days = 14): array
    {
        return $this->get('/expiring', ['days' => $days]);
    }

    /**
     * Get market statistics
     */
    public function getStats(): array
    {
        return $this->get('/stats');
    }

    /**
     * List all media items for a client profile.
     */
    public function getClientMedia(int $postId): array
    {
        return $this->get("/clients/{$postId}/media");
    }

    /**
     * Upload a media file to a client profile.
     */
    public function uploadClientMedia(int $postId, UploadedFile $file, bool $setMain = false): array
    {
        $this->assertRemoteWriteAllowed("/clients/{$postId}/media");

        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to read media file for upload.');
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout($this->mediaUploadTimeout)
                ->attach('file', $handle, $file->getClientOriginalName(), [
                    'Content-Type' => $file->getMimeType() ?: 'application/octet-stream',
                ])
                ->post($this->baseUrl . "/clients/{$postId}/media", [
                    'set_main' => $setMain ? '1' : '0',
                ]);
        } finally {
            fclose($handle);
        }

        return $this->decodeResponse($response, 'POST', "/clients/{$postId}/media");
    }

    /**
     * Delete a media item from a client profile.
     */
    public function deleteClientMedia(int $postId, int $attachmentId): array
    {
        return $this->delete("/clients/{$postId}/media/{$attachmentId}");
    }

    /**
     * Mark one media item as the client's main image.
     */
    public function setClientMainImage(int $postId, int $attachmentId): array
    {
        return $this->patch("/clients/{$postId}/media/{$attachmentId}/set-main");
    }

    private function get(string $path, array $params = []): array
    {
        $response = $this->getResponse($path, $params);

        return $this->decodeResponse($response, 'GET', $path);
    }

    private function getResponse(string $path, array $params = []): Response
    {
        $attempt = 0;

        beginning:
        $attempt++;

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout($this->defaultTimeout)
                ->get($this->baseUrl . $path, $params);
        } catch (ConnectionException $exception) {
            if ($attempt < 3) {
                usleep($this->retryDelayMicros($attempt));
                goto beginning;
            }

            throw $exception;
        }

        if (($response->status() === 429 || $response->serverError()) && $attempt < 3) {
            usleep($this->retryDelayMicros($attempt));
            goto beginning;
        }

        return $response;
    }

    private function post(string $path, array $body = []): array
    {
        $this->assertRemoteWriteAllowed($path);

        $response = Http::withHeaders($this->headers())
            ->timeout($this->defaultTimeout)
            ->post($this->baseUrl . $path, $body);

        return $this->decodeResponse($response, 'POST', $path);
    }

    private function patch(string $path, array $body = []): array
    {
        $this->assertRemoteWriteAllowed($path);

        $response = Http::withHeaders($this->headers())
            ->timeout($this->defaultTimeout)
            ->patch($this->baseUrl . $path, $body);

        return $this->decodeResponse($response, 'PATCH', $path);
    }

    private function delete(string $path): array
    {
        $this->assertRemoteWriteAllowed($path);

        $response = Http::withHeaders($this->headers())
            ->timeout($this->defaultTimeout)
            ->delete($this->baseUrl . $path);

        return $this->decodeResponse($response, 'DELETE', $path);
    }

    private function headers(): array
    {
        $headers = [
            'Authorization' => $this->authHeader,
        ];

        $sharedKey = $this->sharedKeyForPlatform();
        if ($sharedKey !== null) {
            $headers['X-Exotic-CRM-Sync-Key'] = $sharedKey;
        }

        return $headers;
    }

    private function sharedKeyForPlatform(): ?string
    {
        $sharedKey = trim((string) config('services.exotic_crm_sync.shared_key', ''));
        if ($sharedKey === '' || $this->platformId <= 0) {
            return null;
        }

        $platformIds = $this->configuredSharedKeyPlatformIds();
        if (!in_array($this->platformId, $platformIds, true)) {
            return null;
        }

        return $sharedKey;
    }

    private function apiRoot(): string
    {
        return preg_replace('#/wp-json/.*$#', '', $this->baseUrl) ?: $this->baseUrl;
    }

    private function configuredSharedKeyPlatformIds(): array
    {
        $configured = config('services.exotic_crm_sync.shared_key_platform_ids', '');
        if (is_array($configured)) {
            $values = $configured;
        } else {
            $values = preg_split('/[\s,]+/', (string) $configured) ?: [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($value) => (int) $value,
            $values
        ), static fn (int $value) => $value > 0)));
    }

    private function isRemoteEndpoint(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        return !$this->isLocalHost($host);
    }

    private function isLocalHost(string $host): bool
    {
        $normalized = strtolower(trim($host));
        if ($normalized === 'localhost' || $normalized === '127.0.0.1' || $normalized === '::1') {
            return true;
        }

        return str_ends_with($normalized, '.local');
    }

    private function assertRemoteWriteAllowed(string $path): void
    {
        if (!$this->isRemoteEndpoint($this->baseUrl)) {
            return;
        }

        if (app()->environment(['production', 'testing'])) {
            return;
        }

        if ((bool) config('wallet.allow_remote_sync_from_non_production', false)) {
            return;
        }

        $url = $this->baseUrl . $path;
        $message = sprintf(
            'Blocked remote WordPress write to [%s] from [%s] environment.',
            $url,
            app()->environment()
        );

        Log::warning('WpSyncService remote write blocked', [
            'url' => $url,
            'environment' => app()->environment(),
        ]);

        throw new RuntimeException($message);
    }

    private function decodeResponse($response, string $method, string $path): array
    {
        if ($response->failed()) {
            $this->logFailedResponse($response, $method, $path);
            $response->throw();
        }

        return (array) $response->json();
    }

    private function logFailedResponse(Response $response, string $method, string $path): void
    {
        Log::error("WpSyncService {$method} failed", [
            'url' => $this->baseUrl . $path,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
    }

    private function retryDelayMicros(int $attempt): int
    {
        return match ($attempt) {
            1 => 500_000,
            2 => 1_500_000,
            default => 3_000_000,
        };
    }
}
