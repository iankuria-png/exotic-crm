<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Platform;

class WpSyncService
{
    private string $baseUrl;
    private string $authHeader;

    public function __construct(Platform $platform)
    {
        $this->baseUrl = rtrim($platform->wp_api_url, '/');
        $this->authHeader = 'Basic ' . base64_encode(
            $platform->wp_api_user . ':' . $platform->wp_api_password
        );
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
     * Deactivate a client profile
     */
    public function deactivateClient(int $postId): array
    {
        return $this->post("/clients/{$postId}/deactivate");
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

    private function get(string $path, array $params = []): array
    {
        $response = Http::withHeaders([
            'Authorization' => $this->authHeader,
        ])->timeout(30)->get($this->baseUrl . $path, $params);

        if ($response->failed()) {
            Log::error('WpSyncService GET failed', [
                'url'    => $this->baseUrl . $path,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            $response->throw();
        }

        return $response->json();
    }

    private function post(string $path, array $body = []): array
    {
        $response = Http::withHeaders([
            'Authorization' => $this->authHeader,
        ])->timeout(30)->post($this->baseUrl . $path, $body);

        if ($response->failed()) {
            Log::error('WpSyncService POST failed', [
                'url'    => $this->baseUrl . $path,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            $response->throw();
        }

        return $response->json();
    }
}
