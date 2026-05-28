<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Platform;
use App\Support\CrossPlatformPhoneResolver;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Log;

class SubsidiaryClientResolver
{
    public function __construct(
        private readonly CrossPlatformPhoneResolver $phoneResolver
    ) {
    }

    public function resolve(Client $mainClient, Platform $target, array $seed): Client
    {
        $phone = $this->targetPhone($mainClient, $target, $seed);
        $email = trim((string) ($seed['email'] ?? $mainClient->email ?? ''));

        $client = $this->findCrmClient($target, $phone, $email);
        if ($client && (int) ($client->wp_post_id ?? 0) > 0) {
            return $client;
        }

        if ($client) {
            return $this->provisionAndRepairCrmClient($client, $target, $seed, $phone, $email);
        }

        $wpMatch = $this->findWpProfile($target, $phone, $email);
        if ($wpMatch && (int) ($wpMatch['wp_post_id'] ?? 0) > 0) {
            return (new ClientSyncService($target))->syncOne((int) $wpMatch['wp_post_id']);
        }

        return $this->provisionNewClient($target, $seed, $phone, $email);
    }

    public function targetPhone(Client $mainClient, Platform $target, array $seed = []): ?string
    {
        $sourcePrefix = (string) ($mainClient->platform?->phone_prefix ?: '');
        $targetPrefix = (string) ($target->phone_prefix ?: '254');
        $sourcePhone = $seed['phone_normalized'] ?? $mainClient->phone_normalized;

        return $this->phoneResolver->resolve($sourcePhone, $sourcePrefix, $targetPrefix);
    }

    private function findCrmClient(Platform $target, ?string $phone, string $email): ?Client
    {
        if ($phone) {
            $match = Client::query()
                ->where('platform_id', (int) $target->id)
                ->where('phone_normalized', $phone)
                ->latest('id')
                ->first();

            if ($match) {
                return $match;
            }
        }

        if ($email !== '') {
            return Client::query()
                ->where('platform_id', (int) $target->id)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
                ->latest('id')
                ->first();
        }

        return null;
    }

    private function provisionAndRepairCrmClient(Client $client, Platform $target, array $seed, ?string $phone, string $email): Client
    {
        $result = $this->provisionWpProfile($target, $seed, $phone, $email);

        $client->forceFill([
            'wp_post_id' => (int) $result['wp_post_id'],
            'wp_user_id' => (int) ($result['wp_user_id'] ?? 0) ?: null,
            'name' => $seed['name'] ?? $client->name,
            'phone_normalized' => $phone ?: $client->phone_normalized,
            'email' => $email !== '' ? $email : $client->email,
            'city' => $seed['city'] ?? $client->city,
            'profile_status' => (string) ($result['wp_post_status'] ?? $client->profile_status ?? 'private'),
            'signup_source' => $client->signup_source ?: 'crm_provisioned',
            'last_synced_at' => now(),
        ])->save();

        try {
            return (new ClientSyncService($target))->syncOne((int) $result['wp_post_id']);
        } catch (\Throwable $exception) {
            Log::warning('Subsidiary client repair sync failed after provisioning', [
                'client_id' => $client->id,
                'platform_id' => $target->id,
                'wp_post_id' => $result['wp_post_id'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            return $client->fresh(['platform']) ?? $client;
        }
    }

    private function provisionNewClient(Platform $target, array $seed, ?string $phone, string $email): Client
    {
        $result = $this->provisionWpProfile($target, $seed, $phone, $email);

        try {
            return (new ClientSyncService($target))->syncOne((int) $result['wp_post_id']);
        } catch (\Throwable $exception) {
            throw new SubsidiaryProvisioningException(
                'WordPress profile was provisioned, but CRM sync failed: ' . $exception->getMessage(),
                'client_sync_failed'
            );
        }
    }

    private function provisionWpProfile(Platform $target, array $seed, ?string $phone, string $email): array
    {
        try {
            return (new WpDirectProvisioningService($target))->provisionEscort([
                'name' => trim((string) ($seed['name'] ?? '')) ?: 'Client',
                'email' => $email,
                'phone' => $phone ?: '',
                'whatsapp' => $phone ?: '',
                'city' => trim((string) ($seed['city'] ?? '')),
                'post_status' => 'private',
            ]);
        } catch (\Throwable $exception) {
            throw new SubsidiaryProvisioningException(
                'WordPress provisioning failed: ' . $exception->getMessage(),
                'wp_provisioning_failed'
            );
        }
    }

    private function findWpProfile(Platform $target, ?string $phone, string $email): ?array
    {
        try {
            $wp = WpSyncService::forPlatform((int) $target->id);
            foreach ([$phone, $email] as $term) {
                $term = trim((string) $term);
                if ($term === '') {
                    continue;
                }

                $response = $wp->searchClients($term, 20);
                $profiles = is_array($response['data'] ?? null) ? $response['data'] : [];
                foreach ($profiles as $profile) {
                    $profilePhone = PhoneNormalizer::normalize($profile['phone'] ?? null, (string) ($target->phone_prefix ?: '254'));
                    $profileEmail = mb_strtolower(trim((string) ($profile['email'] ?? '')));

                    if (($phone && $profilePhone === $phone) || ($email !== '' && $profileEmail === mb_strtolower($email))) {
                        return $profile;
                    }
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('Subsidiary WP lookup failed before provisioning', [
                'platform_id' => $target->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return null;
    }
}
