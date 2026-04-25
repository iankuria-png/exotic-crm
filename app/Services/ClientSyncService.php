<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientSyncExclusion;
use App\Models\Platform;
use App\Support\CityNormalizer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ClientSyncService
{
    private const DELTA_OVERLAP_MINUTES = 5;

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
        $skipped = 0;
        $total = 0;

        do {
            $response = $this->wpSync->getClients($page, $perPage);
            $clients = $response['data'] ?? [];
            $totalPages = $response['pages'] ?? 1;

            foreach ($clients as $wpClient) {
                $result = $this->upsertClient($wpClient);
                if ($result === 'created') {
                    $created++;
                } elseif ($result === 'skipped') {
                    $skipped++;
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
            'skipped' => $skipped,
            'total'   => $total,
        ];
    }

    /**
     * Delta sync: only import profiles modified after the last sync
     */
    public function deltaSync(int $perPage = 100): array
    {
        $modifiedAfter = $this->resolveDeltaModifiedAfter();

        $page = 1;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $total = 0;

        do {
            $response = $this->wpSync->getClients($page, $perPage, $modifiedAfter);
            $clients = $response['data'] ?? [];
            $totalPages = $response['pages'] ?? 1;

            foreach ($clients as $wpClient) {
                $result = $this->upsertClient($wpClient);
                if ($result === 'created') {
                    $created++;
                } elseif ($result === 'skipped') {
                    $skipped++;
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
            'skipped' => $skipped,
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
        $wpPostId = (int) ($wpClient['wp_post_id'] ?? 0);
        if ($wpPostId > 0) {
            $isExcluded = ClientSyncExclusion::query()
                ->where('platform_id', (int) $this->platform->id)
                ->where('wp_post_id', $wpPostId)
                ->exists();

            if ($isExcluded) {
                return 'skipped';
            }
        }

        $phone = $this->normalizePhone($wpClient['phone'] ?? '', $this->platform->phone_prefix);

        // Truncate fields to fit column limits — WP data can have junk
        $phone = mb_substr($phone, 0, 20);
        $name = mb_substr($wpClient['name'] ?? '', 0, 255);
        $email = mb_substr($wpClient['email'] ?? '', 0, 255);
        $city = CityNormalizer::fromWpPayload($wpClient);
        $imageUrl = mb_substr($wpClient['main_image_url'] ?? '', 0, 500);
        $premiumExpire = $this->ensureUnixTimestamp($wpClient['premium_expire'] ?? null);
        $featuredExpire = $this->ensureUnixTimestamp($wpClient['featured_expire'] ?? null);
        $escortExpire = $this->resolveEscortExpiry($wpClient, $premiumExpire, $featuredExpire);

        $client = Client::firstOrNew([
            'platform_id' => $this->platform->id,
            'wp_post_id'  => $wpPostId,
        ]);

        $newBadgeMode = $this->resolveNewBadgeMode($wpClient);

        $syncData = [
            'wp_user_id'      => $wpClient['wp_user_id'] ?? null,
            'client_type'     => 'escort',
            'name'            => $name ?: null,
            'phone_normalized'=> $phone ?: null,
            'email'           => $email ?: null,
            'city'            => $city ?? $client->city,
            'profile_status'  => $wpClient['post_status'] ?? 'private',
            'premium'         => (bool) ($wpClient['premium'] ?? false),
            'premium_expire'  => $premiumExpire,
            'featured'        => (bool) ($wpClient['featured'] ?? false),
            'featured_expire' => $featuredExpire,
            'escort_expire'   => $escortExpire,
            'verified'        => (bool) ($wpClient['verified'] ?? false),
            'force_new'       => $newBadgeMode === 'force_on',
            'new_badge_mode'  => $newBadgeMode,
            'last_online_at'  => $this->ensureUnixTimestamp($wpClient['last_online'] ?? null),
            'main_image_url'  => $imageUrl ?: null,
            'last_synced_at'  => now(),
            'wp_modified_at'  => $this->normalizeWpModifiedAt($wpClient['modified_at'] ?? null),
        ];

        if (array_key_exists('needs_payment', $wpClient)) {
            $syncData['needs_payment'] = (bool) ($wpClient['needs_payment'] ?? false);
        }

        if (array_key_exists('notactive', $wpClient)) {
            $syncData['notactive'] = (bool) ($wpClient['notactive'] ?? false);
        }

        // Only write signup_source if WP provides one (prevents clobbering crm_provisioned/crm_manual)
        $wpSignupSource = $wpClient['signup_source'] ?? null;
        if ($wpSignupSource !== null) {
            $syncData['signup_source'] = $wpSignupSource;
        }

        $client->fill($syncData);
        $client->save();

        return $client->wasRecentlyCreated ? 'created' : 'updated';
    }

    private function resolveNewBadgeMode(array $wpClient): string
    {
        $mode = strtolower(trim((string) ($wpClient['new_badge_mode'] ?? '')));

        if (in_array($mode, ['auto', 'force_on', 'force_off'], true)) {
            return $mode;
        }

        return !empty($wpClient['force_new']) ? 'force_on' : 'auto';
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

    private function resolveEscortExpiry(array $wpClient, ?int $premiumExpire, ?int $featuredExpire): ?int
    {
        $directExpiry = $this->ensureUnixTimestamp($wpClient['escort_expire'] ?? null);
        if ($directExpiry !== null) {
            return $directExpiry;
        }

        $fallbacks = array_values(array_filter([
            $premiumExpire,
            $featuredExpire,
        ], static fn($value) => $value !== null));

        if (empty($fallbacks)) {
            return null;
        }

        return max($fallbacks);
    }

    private function resolveDeltaModifiedAfter(): ?string
    {
        $lastWpModifiedAt = Client::query()
            ->where('platform_id', $this->platform->id)
            ->whereNotNull('wp_modified_at')
            ->max('wp_modified_at');

        if (!$lastWpModifiedAt) {
            return null;
        }

        return Carbon::parse((string) $lastWpModifiedAt, 'UTC')
            ->subMinutes(self::DELTA_OVERLAP_MINUTES)
            ->toIso8601String();
    }

    private function normalizeWpModifiedAt($value): ?Carbon
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        try {
            return Carbon::parse((string) $value, 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
