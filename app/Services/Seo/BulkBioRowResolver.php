<?php

namespace App\Services\Seo;

use App\Models\Client;
use Illuminate\Support\Str;

/**
 * Parses pasted editor input (Excel rows, free-form URLs, mixed) into a
 * normalized list of {@see SeoBioBatchRow} candidates, and resolves each
 * row to a CRM Client where possible.
 *
 * Accepted paste formats:
 *   - One URL per line
 *   - Tab-separated rows (first non-empty cell wins; other cells ignored)
 *   - Comma-separated cells (same)
 *   - Bare WP post IDs ("12345")
 *   - Slugs ("some-girl-name")
 *
 * Resolution strategy (in order):
 *   1. Match wp_profile_url / wp_profile_permalink directly
 *   2. Extract slug from URL path → match wp_profile_slug
 *   3. Bare numeric → wp_post_id
 *   4. Otherwise: status=unresolved with the original text retained for the
 *      editor to fix manually.
 *
 * Returns rows scoped to a single platform_id so we don't accidentally
 * generate cross-market bios from a paste that mixed domains.
 */
class BulkBioRowResolver
{
    /** Hard cap on rows per batch — protects against accidental 10K paste. */
    public const MAX_ROWS = 250;

    /**
     * @return array<int, array{
     *     row_index: int,
     *     input_text: string,
     *     input_url: ?string,
     *     wp_post_id: ?int,
     *     client_id: ?int,
     *     profile_name: ?string,
     *     status: string,
     *     error: ?string,
     * }>
     */
    public function parse(string $paste, int $platformId): array
    {
        $lines = $this->splitLines($paste);
        $rows = [];
        $seen = [];   // dedupe by URL/slug/post_id
        $index = 0;

        foreach ($lines as $raw) {
            if ($index >= self::MAX_ROWS) {
                break;
            }
            $token = $this->extractToken($raw);
            if ($token === '') {
                continue;
            }

            $candidate = $this->classify($token);
            $key = $candidate['dedupe_key'];
            if ($key !== null && isset($seen[$key])) {
                continue;
            }
            if ($key !== null) {
                $seen[$key] = true;
            }

            $resolved = $this->resolve($candidate, $platformId);

            $rows[] = [
                'row_index'   => ++$index,
                'input_text'  => mb_substr($raw, 0, 600),
                'input_url'   => $candidate['url'] ? mb_substr($candidate['url'], 0, 600) : null,
                'wp_post_id'  => $resolved['wp_post_id'] ?? null,
                'client_id'   => $resolved['client_id'] ?? null,
                'profile_name' => $resolved['profile_name'] ?? null,
                'status'      => $resolved['wp_post_id'] || $resolved['client_id']
                    ? 'queued'
                    : 'unresolved',
                'error'       => $resolved['error'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Quick summary for the dry-run / before-confirm view.
     *
     * @return array{total: int, resolved: int, unresolved: int}
     */
    public function summarize(array $rows): array
    {
        $resolved = 0;
        $unresolved = 0;
        foreach ($rows as $row) {
            if ($row['status'] === 'unresolved') {
                $unresolved++;
            } else {
                $resolved++;
            }
        }

        return [
            'total'      => count($rows),
            'resolved'   => $resolved,
            'unresolved' => $unresolved,
        ];
    }

    // -------------------------------------------------------------------

    private function splitLines(string $paste): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $paste);
        return array_filter(
            array_map('trim', explode("\n", $normalized)),
            fn($line) => $line !== ''
        );
    }

    /**
     * Pick the first non-empty, URL-or-slug-looking cell from a tab/comma row.
     * Editors often paste rows like "Name\tURL\tCity"; we want the URL.
     */
    private function extractToken(string $line): string
    {
        $line = trim($line);
        if ($line === '') {
            return '';
        }
        // Split on tab first (Excel paste default), then comma.
        $cells = preg_split('/[\t,]/', $line) ?: [$line];
        foreach ($cells as $cell) {
            $cell = trim($cell);
            if ($cell === '') {
                continue;
            }
            // Prefer cells that look like URLs/slugs/IDs.
            if ($this->looksLikeLocator($cell)) {
                return $cell;
            }
        }
        // No locator-looking cell — return the first non-empty so we still
        // record an unresolved row for the editor to fix.
        foreach ($cells as $cell) {
            $cell = trim($cell);
            if ($cell !== '') {
                return $cell;
            }
        }
        return '';
    }

    private function looksLikeLocator(string $cell): bool
    {
        if (ctype_digit($cell) && (int) $cell > 0) {
            return true; // bare post ID
        }
        if (Str::startsWith($cell, ['http://', 'https://', '//', '/'])) {
            return true;
        }
        if (preg_match('/^[a-z0-9-]{3,}$/i', $cell)) {
            return true; // slug-ish
        }
        return false;
    }

    /**
     * Classify a token as URL / slug / post_id and produce a dedupe key.
     *
     * @return array{kind: string, url: ?string, slug: ?string, post_id: ?int, dedupe_key: ?string}
     */
    private function classify(string $token): array
    {
        if (ctype_digit($token)) {
            $id = (int) $token;
            return [
                'kind'       => 'post_id',
                'url'        => null,
                'slug'       => null,
                'post_id'    => $id,
                'dedupe_key' => "id:{$id}",
            ];
        }

        if (Str::startsWith($token, ['http://', 'https://', '//', '/'])) {
            $url = Str::startsWith($token, '//') ? 'https:' . $token : $token;
            $parsed = parse_url($url);
            $path = isset($parsed['path']) ? trim($parsed['path'], '/') : '';
            // Last path segment is typically the slug (escort/jane-doe → jane-doe)
            $segments = $path !== '' ? explode('/', $path) : [];
            $slug = $segments !== [] ? end($segments) : null;
            return [
                'kind'       => 'url',
                'url'        => $url,
                'slug'       => $slug,
                'post_id'    => null,
                'dedupe_key' => 'url:' . strtolower($url),
            ];
        }

        if (preg_match('/^[a-z0-9-]+$/i', $token)) {
            $slug = strtolower($token);
            return [
                'kind'       => 'slug',
                'url'        => null,
                'slug'       => $slug,
                'post_id'    => null,
                'dedupe_key' => "slug:{$slug}",
            ];
        }

        return [
            'kind'       => 'unknown',
            'url'        => null,
            'slug'       => null,
            'post_id'    => null,
            'dedupe_key' => null,
        ];
    }

    private function resolve(array $candidate, int $platformId): array
    {
        $client = null;

        if (!empty($candidate['post_id'])) {
            $client = Client::query()
                ->where('platform_id', $platformId)
                ->where('wp_post_id', $candidate['post_id'])
                ->first();
        }

        if (!$client && !empty($candidate['url'])) {
            $client = Client::query()
                ->where('platform_id', $platformId)
                ->where('wp_profile_permalink', $candidate['url'])
                ->first();
        }

        if (!$client && !empty($candidate['slug'])) {
            $client = Client::query()
                ->where('platform_id', $platformId)
                ->where('wp_profile_slug', $candidate['slug'])
                ->first();
        }

        if (!$client) {
            return [
                'wp_post_id'   => $candidate['post_id'] ?? null,
                'client_id'    => null,
                'profile_name' => null,
                'error'        => 'No CRM client matches this URL/slug/ID on the selected market.',
            ];
        }

        return [
            'wp_post_id'   => (int) $client->wp_post_id,
            'client_id'    => (int) $client->id,
            'profile_name' => (string) ($client->name ?: $client->wp_profile_slug ?: ''),
            'error'        => null,
        ];
    }
}
