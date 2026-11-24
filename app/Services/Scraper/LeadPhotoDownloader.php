<?php

namespace App\Services\Scraper;

use App\Services\Scraper\Fetch\UrlSafety;
use App\Services\Scraper\Fetch\UrlTools;
use Illuminate\Support\Facades\Http;

/**
 * Downloads one scraped listing photo for a converted lead. The URL came from a third-party
 * page, so every redirect hop is checked against private networks, the body is capped, and
 * only a real JPEG/PNG/WebP large enough to be a profile photo (not a logo or icon) passes.
 *
 * Sends the listing page as Referer: many sites block image requests without one.
 */
class LeadPhotoDownloader
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    public const MIN_SIDE = 200;

    private const TYPES = [
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
        IMAGETYPE_PNG => ['image/png', 'png'],
        IMAGETYPE_WEBP => ['image/webp', 'webp'],
    ];

    public function __construct(private readonly UrlSafety $urlSafety) {}

    /**
     * Success: ok, bytes, mime, extension, width, height, final_url. Failure: ok false, error_code, error.
     *
     * @return array<string, mixed>
     */
    public function download(string $url, ?string $referer): array
    {
        $maxRedirects = (int) config('scraper.http.max_redirects', 5);

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $unsafe = $this->urlSafety->unsafeReason($url);
            if ($unsafe !== null) {
                return $this->fail('unsafe_url', $unsafe);
            }

            try {
                $response = Http::withHeaders(array_filter([
                    'User-Agent' => (string) config('scraper.user_agent'),
                    'Accept' => 'image/avif,image/webp,image/jpeg,image/png,image/*;q=0.8',
                    'Referer' => $referer && $this->urlSafety->unsafeReason($referer) === null ? $referer : null,
                ]))
                    ->withoutRedirecting()
                    ->connectTimeout((int) config('scraper.http.connect_timeout', 6))
                    ->timeout(30)
                    ->get($url);
            } catch (\Throwable $e) {
                return $this->fail('fetch_failed', 'Could not download the photo: '.mb_substr($e->getMessage(), 0, 160));
            }

            $status = $response->status();
            $location = (string) $response->header('location');
            if ($status >= 300 && $status < 400 && $location !== '') {
                $url = UrlTools::resolve($url, $location) ?? $location;

                continue;
            }
            if ($status !== 200) {
                return $this->fail('http_'.$status, "The listing site answered {$status} for the photo.");
            }

            $declared = (int) $response->header('content-length');
            $bytes = (string) $response->body();
            if ($declared > self::MAX_BYTES || strlen($bytes) > self::MAX_BYTES) {
                return $this->fail('too_large', 'The photo is larger than 8 MB.');
            }

            $info = $bytes !== '' ? @getimagesizefromstring($bytes) : false;
            if (! is_array($info) || ! isset(self::TYPES[$info[2] ?? 0])) {
                return $this->fail('not_image', 'The address did not return a JPEG, PNG or WebP photo.');
            }
            [$width, $height] = [(int) $info[0], (int) $info[1]];
            if (min($width, $height) < self::MIN_SIDE) {
                return $this->fail('too_small', "The photo is only {$width}×{$height}, too small for a profile.");
            }

            [$mime, $extension] = self::TYPES[$info[2]];

            return ['ok' => true, 'bytes' => $bytes, 'mime' => $mime, 'extension' => $extension, 'width' => $width, 'height' => $height, 'final_url' => $url];
        }

        return $this->fail('too_many_redirects', 'The photo address redirected too many times.');
    }

    private function fail(string $code, string $message): array
    {
        return ['ok' => false, 'error_code' => $code, 'error' => $message];
    }
}
