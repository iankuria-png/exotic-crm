<?php

namespace App\Services\PushCampaign;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Detects push images that are definitely gone (404/410) before a send.
 *
 * Every subscribed device fetches the notification image at the same moment, so a
 * deleted WordPress upload turns one send into thousands of full WordPress 404 renders.
 * Only a definite 404/410 counts as missing: 403s, Cloudflare challenges, 5xx and
 * timeouts keep the image, because bot protection once made a reachability check drop
 * images that were fine.
 */
class PushImageGuard
{
    private const CACHE_TTL_SECONDS = 3600;

    public function isDefinitelyMissing(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return false;
        }

        return (bool) Cache::remember(
            'push-image-missing:' . sha1($url),
            self::CACHE_TTL_SECONDS,
            function () use ($url): bool {
                try {
                    $response = Http::withHeaders([
                        'User-Agent' => 'ExoticCRM-ImageCheck/1.0',
                        'Accept' => 'image/*,*/*;q=0.1',
                    ])
                        ->withOptions(['allow_redirects' => true])
                        ->connectTimeout(2)
                        ->timeout(3)
                        ->head($url);
                } catch (Throwable) {
                    return false;
                }

                return in_array($response->status(), [404, 410], true);
            }
        );
    }
}
