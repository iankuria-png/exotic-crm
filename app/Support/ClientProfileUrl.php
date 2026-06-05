<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Platform;

final class ClientProfileUrl
{
    public static function resolve(Client $client, Platform $platform): ?string
    {
        if (!empty($client->wp_profile_url)) {
            return (string) $client->wp_profile_url;
        }

        $wpPostId = (int) ($client->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            return null;
        }

        $domain = trim((string) ($platform->domain ?? ''));
        if ($domain === '') {
            return null;
        }

        if (!preg_match('#^https?://#i', $domain)) {
            $domain = 'https://' . $domain;
        }

        return rtrim($domain, '/') . '/?p=' . $wpPostId;
    }
}
