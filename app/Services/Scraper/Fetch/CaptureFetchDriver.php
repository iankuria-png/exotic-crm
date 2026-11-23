<?php

namespace App\Services\Scraper\Fetch;

use App\Models\ScraperCapture;
use Illuminate\Support\Facades\Storage;

/**
 * Serves pages that a person captured in their own browser (bookmarklet, upload or
 * paste). Matches the newest received capture for the source and exact URL, falling
 * back to the newest capture on the same host for the first page.
 */
class CaptureFetchDriver implements FetchDriver
{
    public function key(): string
    {
        return 'capture';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function fetch(FetchRequest $request): FetchResult
    {
        $capture = $this->find($request->sourceId, $request->url);
        if (!$capture) {
            return FetchResult::failure($request->url, $this->key(), 'No browser capture yet for this page. Use "Capture from your browser" first.');
        }

        $disk = Storage::disk((string) config('scraper.capture.disk', 'local'));
        if (!$capture->storage_path || !$disk->exists($capture->storage_path)) {
            return FetchResult::failure($request->url, $this->key(), 'The captured page file is missing.');
        }

        $html = (string) $disk->get($capture->storage_path);
        $finalUrl = (string) ($capture->url ?: $request->url);

        return new FetchResult($request->url, $finalUrl, 200, ['content-type' => 'text/html; charset=utf-8', 'x-capture-id' => (string) $capture->id], $html, strlen($html), 0, $this->key());
    }

    public function find(?int $sourceId, string $url): ?ScraperCapture
    {
        if ($sourceId === null) {
            return null;
        }

        $base = ScraperCapture::query()
            ->where('scraper_source_id', $sourceId)
            ->where('status', 'received')
            ->orderByDesc('received_at')
            ->orderByDesc('id');

        $normalized = self::normalize($url);
        $sameHost = null;
        foreach ((clone $base)->limit(50)->get() as $capture) {
            if (self::normalize((string) $capture->url) === $normalized) {
                return $capture;
            }
            if ($sameHost === null && UrlTools::host((string) $capture->url) === UrlTools::host($url)) {
                $sameHost = $capture;
            }
        }

        // Fall back to the newest capture on the same site (first page / slightly different URL).
        return $sameHost;
    }

    public function latestForSource(?int $sourceId): ?ScraperCapture
    {
        if ($sourceId === null) {
            return null;
        }

        return ScraperCapture::query()
            ->where('scraper_source_id', $sourceId)
            ->where('status', 'received')
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->first();
    }

    private static function normalize(string $url): string
    {
        $url = strtok($url, '#') ?: $url;
        $url = preg_replace('#^https?://(www\.)?#i', '', $url) ?? $url;

        return strtolower(rtrim($url, '/'));
    }
}
