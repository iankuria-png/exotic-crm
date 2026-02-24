<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Platform;
use Illuminate\Support\Facades\Log;

class ClientSyncService
{
    private WpSyncService $wpSync;
    private Platform $platform;

    public function __construct(Platform $platform)
    {
        $this->platform = $platform;
        $this->wpSync = new WpSyncService($platform);
    }

    /**
     * Full sync: import all profiles from WordPress to CRM clients table
     * Returns count of created, updated, and total records
     */
    public function fullSync(int $perPage = 100): array
    {
        $page = 1;
        $created = 0;
        $updated = 0;
        $total = 0;

        do {
            $response = $this->wpSync->getClients($page, $perPage);
            $clients = $response['data'] ?? [];
            $totalPages = $response['pages'] ?? 1;

            foreach ($clients as $wpClient) {
                $result = $this->upsertClient($wpClient);
                if ($result === 'created') {
                    $created++;
                } else {
                    $updated++;
                }
                $total++;
            }

            Log::info("ClientSync page {$page}/{$totalPages}", [
                'platform_id' => $this->platform->id,
                'records' => count($clients),
                'running_total' => $total,
            ]);

            $page++;
        } while ($page <= $totalPages);

        return [
            'created' => $created,
            'updated' => $updated,
            'total'   => $total,
        ];
    }

    /**
     * Delta sync: only import profiles modified after the last sync
     */
    public function deltaSync(): array
    {
        $lastSync = Client::where('platform_id', $this->platform->id)
            ->max('last_synced_at');

        $modifiedAfter = $lastSync
            ? \Carbon\Carbon::parse($lastSync)->toIso8601String()
            : null;

        $page = 1;
        $created = 0;
        $updated = 0;
        $total = 0;

        do {
            $response = $this->wpSync->getClients($page, 100, $modifiedAfter);
            $clients = $response['data'] ?? [];
            $totalPages = $response['pages'] ?? 1;

            foreach ($clients as $wpClient) {
                $result = $this->upsertClient($wpClient);
                if ($result === 'created') {
                    $created++;
                } else {
                    $updated++;
                }
                $total++;
            }

            $page++;
        } while ($page <= $totalPages);

        return [
            'created' => $created,
            'updated' => $updated,
            'total'   => $total,
        ];
    }

    /**
     * Sync a single client by WP post ID
     */
    public function syncOne(int $wpPostId): Client
    {
        $wpClient = $this->wpSync->getClient($wpPostId);
        $this->upsertClient($wpClient);

        return Client::where('platform_id', $this->platform->id)
            ->where('wp_post_id', $wpPostId)
            ->firstOrFail();
    }

    private function upsertClient(array $wpClient): string
    {
        $phone = $this->normalizePhone($wpClient['phone'] ?? '', $this->platform->phone_prefix);

        // Truncate fields to fit column limits — WP data can have junk
        $phone = mb_substr($phone, 0, 20);
        $name = mb_substr($wpClient['name'] ?? '', 0, 255);
        $email = mb_substr($wpClient['email'] ?? '', 0, 255);
        $city = mb_substr($wpClient['city'] ?? '', 0, 100);
        $imageUrl = mb_substr($wpClient['main_image_url'] ?? '', 0, 500);

        $client = Client::updateOrCreate(
            [
                'platform_id' => $this->platform->id,
                'wp_post_id'  => $wpClient['wp_post_id'],
            ],
            [
                'wp_user_id'      => $wpClient['wp_user_id'] ?? null,
                'client_type'     => 'escort',
                'name'            => $name ?: null,
                'phone_normalized'=> $phone ?: null,
                'email'           => $email ?: null,
                'city'            => $city ?: null,
                'profile_status'  => $wpClient['post_status'] ?? 'private',
                'premium'         => (bool) ($wpClient['premium'] ?? false),
                'premium_expire'  => $this->ensureUnixTimestamp($wpClient['premium_expire'] ?? null),
                'featured'        => (bool) ($wpClient['featured'] ?? false),
                'featured_expire' => $this->ensureUnixTimestamp($wpClient['featured_expire'] ?? null),
                'escort_expire'   => $this->ensureUnixTimestamp($wpClient['escort_expire'] ?? null),
                'verified'        => (bool) ($wpClient['verified'] ?? false),
                'last_online_at'  => $this->ensureUnixTimestamp($wpClient['last_online'] ?? null),
                'main_image_url'  => $imageUrl ?: null,
                'last_synced_at'  => now(),
            ]
        );

        return $client->wasRecentlyCreated ? 'created' : 'updated';
    }

    /**
     * Normalize phone number to international format (e.g., 254712345678)
     */
    private function normalizePhone(?string $phone, string $prefix = '254'): string
    {
        if (!$phone) {
            return '';
        }

        // Remove all non-digit characters except leading +
        $phone = preg_replace('/[^\d+]/', '', $phone);

        // Remove leading +
        $phone = ltrim($phone, '+');

        // If starts with 0, replace with country prefix
        if (str_starts_with($phone, '0')) {
            $phone = $prefix . substr($phone, 1);
        }

        return $phone;
    }

    private function ensureUnixTimestamp($value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $ts = strtotime((string) $value);

        return $ts !== false ? $ts : null;
    }
}
