<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Platform;

final class ClientProfileUrl
{
    public static function resolve(Client $client, Platform $platform): ?string
    {
        // Prefer the pretty permalink synced from WordPress. The wp_profile_url
        // accessor on Client is a computed "{domain}/?p={id}" fallback — using it
        // here would send WordPress the /?p=NN form even when a real permalink is
        // known, and /?p= can silently redirect to the homepage when the target
        // post is archived, private, or deleted.
        $permalink = trim((string) ($client->wp_profile_permalink ?? ''));
        if ($permalink !== '') {
            return $permalink;
        }

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
