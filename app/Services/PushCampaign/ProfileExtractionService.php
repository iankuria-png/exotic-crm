<?php

namespace App\Services\PushCampaign;

use App\Models\Client;
use App\Models\Platform;
use App\Models\PushCampaignItem;
use App\Models\ScraperProfilePreset;
use App\Services\WpSyncService;
use App\Support\DomParserTrait;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProfileExtractionService
{
    use DomParserTrait;

    private const SHEET_ALIASES = [
        'IVOIRE' => 'Ivory Coast',
        'S.SUDAN' => 'South Sudan',
        'SUDAN SOUTH' => 'South Sudan',
        'DRC' => 'Congo',
    ];

    private const SKIPPED_SHEETS = [
        'GUIDELINES',
        'INTERSTITIAL ADS',
        'INTERSTITIAL',
    ];
    private const FILENAME_STOP_WORDS = [
        'push',
        'workbook',
        'document',
        'campaign',
        'campaigns',
        'sheet',
        'sheets',
        'upload',
        'uploads',
        'notification',
        'notifications',
        'country',
    ];

    public function __construct(
        private readonly PushCampaignItemMatchService $pushCampaignItemMatchService,
    ) {
    }

    public function shouldSkipSheet(string $sheetName): bool
    {
        return in_array(strtoupper(trim($sheetName)), self::SKIPPED_SHEETS, true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseSheet(Worksheet $sheet, string $sheetName, int $year): array
    {
        $parsed = $this->parseSheetChunk(
            $sheet,
            $sheetName,
            $year,
            2,
            (int) $sheet->getHighestDataRow(),
            null,
            null
        );

        return $parsed['rows'];
    }

    /**
     * @param Platform|null $resolvedPlatform Pass pre-resolved platform to avoid repeated lookups in chunked processing.
     * @return array{rows: array<int, array<string, mixed>>, last_date_label: string|null}
     */
    public function parseSheetChunk(
        Worksheet $sheet,
        string $sheetName,
        int $year,
        int $startRow,
        int $endRow,
        ?string $carriedDateLabel = null,
        ?Platform $resolvedPlatform = null
    ): array {
        $platform = $resolvedPlatform ?: $this->resolveSheetToPlatform($sheetName);
        if (!$platform) {
            return [
                'rows' => [],
                'last_date_label' => $carriedDateLabel,
            ];
        }

        $rows = [];
        $currentDateLabel = $carriedDateLabel;
        $start = max(2, $startRow);
        $end = max($start, $endRow);

        for ($row = $start; $row <= $end; $row++) {
            $dateRaw = $this->normalizeCellText($sheet->getCell('A' . $row)->getFormattedValue());
            $profileUrl = $this->normalizeCellText($sheet->getCell('B' . $row)->getFormattedValue());
            $message = $this->normalizeCellText($sheet->getCell('C' . $row)->getFormattedValue());
            $time = $this->resolveTimeValue($sheet, $row);

            if ($dateRaw !== '') {
                $currentDateLabel = $dateRaw;
            }

            if ($profileUrl === '' && $message === '' && $time === '') {
                continue;
            }

            if (!$currentDateLabel || $profileUrl === '' || $message === '') {
                continue;
            }

            $scheduledAt = $this->parseScheduledAt($currentDateLabel, $time, $year, $platform);

            $rows[] = [
                'platform_id' => (int) $platform->id,
                'profile_url' => $profileUrl,
                'custom_message' => $message,
                'scheduled_at' => $scheduledAt?->toDateTimeString(),
                'date_label' => $currentDateLabel,
                'status' => 'pending_extraction',
            ];
        }

        return [
            'rows' => $rows,
            'last_date_label' => $currentDateLabel,
        ];
    }

    public function resolveSheetToPlatform(string $sheetName): ?Platform
    {
        $trimmed = trim($sheetName);
        if ($trimmed === '') {
            return null;
        }

        $upper = strtoupper($trimmed);
        if (in_array($upper, self::SKIPPED_SHEETS, true)) {
            return null;
        }

        $alias = self::SHEET_ALIASES[$upper] ?? $trimmed;
        $candidates = array_values(array_unique([$trimmed, $alias]));

        foreach ($candidates as $candidate) {
            $normalized = strtolower(trim($candidate));
            $platform = Platform::query()
                ->whereRaw('LOWER(name) = ?', [$normalized])
                ->orWhereRaw('LOWER(country) = ?', [$normalized])
                ->first();

            if ($platform) {
                return $platform;
            }
        }

        foreach ($candidates as $candidate) {
            $normalized = strtolower(trim($candidate));
            $platform = Platform::query()
                ->whereRaw('LOWER(name) LIKE ?', ['%' . $normalized . '%'])
                ->orWhereRaw('LOWER(country) LIKE ?', ['%' . $normalized . '%'])
                ->first();

            if ($platform) {
                return $platform;
            }
        }

        return null;
    }

    public function resolvePlatformForSheet(string $sheetName, ?string $sourceFilename = null, bool $singleSheetUpload = false): ?Platform
    {
        $platform = $this->resolveSheetToPlatform($sheetName);
        if ($platform) {
            return $platform;
        }

        if (!$singleSheetUpload || !$sourceFilename) {
            return null;
        }

        return $this->resolveFilenameToPlatform($sourceFilename);
    }

    public function resolveFilenameToPlatform(string $sourceFilename): ?Platform
    {
        $basename = pathinfo($sourceFilename, PATHINFO_FILENAME);
        $normalized = trim((string) preg_replace('/[^a-z0-9]+/i', ' ', strtolower($basename)));

        if ($normalized === '') {
            return null;
        }

        $tokens = collect(preg_split('/\s+/', $normalized) ?: [])
            ->map(fn($token) => trim((string) $token))
            ->filter(fn($token) => $token !== '')
            ->filter(fn($token) => !preg_match('/^\d+$/', $token))
            ->filter(fn($token) => !in_array($token, self::FILENAME_STOP_WORDS, true))
            ->values()
            ->all();

        $candidates = array_values(array_unique(array_filter(array_merge(
            [$normalized],
            [trim((string) implode(' ', $tokens))],
            $tokens
        ))));

        foreach ($candidates as $candidate) {
            $mapped = self::SHEET_ALIASES[strtoupper($candidate)] ?? $candidate;
            $platform = Platform::query()
                ->whereRaw('LOWER(name) = ?', [strtolower($mapped)])
                ->orWhereRaw('LOWER(country) = ?', [strtolower($mapped)])
                ->first();

            if ($platform) {
                return $platform;
            }
        }

        foreach ($candidates as $candidate) {
            $mapped = self::SHEET_ALIASES[strtoupper($candidate)] ?? $candidate;
            $platform = Platform::query()
                ->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($mapped) . '%'])
                ->orWhereRaw('LOWER(country) LIKE ?', ['%' . strtolower($mapped) . '%'])
                ->first();

            if ($platform) {
                return $platform;
            }
        }

        return null;
    }

    public function extractProfileBatch(Collection $items, Platform $platform): void
    {
        if ($items->isEmpty()) {
            return;
        }

        $hasWpIntegration = $this->hasWpIntegration($platform);
        $wpSync = $hasWpIntegration ? new WpSyncService($platform) : null;
        $phonePrefix = (string) ($platform->phone_prefix ?: '254');

        foreach ($items as $item) {
            if (!$item instanceof PushCampaignItem) {
                continue;
            }

            $updates = [];

            try {
                if ($hasWpIntegration) {
                    $updates = array_merge($updates, $this->extractViaWp($item, $platform, $wpSync));
                } else {
                    $updates = array_merge($updates, $this->extractViaPreset($item, $platform, $phonePrefix));
                }
            } catch (\Throwable $exception) {
                $updates = array_merge($updates, [
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                ]);
            }

            $item->forceFill($updates)->save();

            if ($hasWpIntegration && $wpSync) {
                usleep(500000);
            }
        }
    }

    private function extractViaWp(PushCampaignItem $item, Platform $platform, ?WpSyncService $wpSync): array
    {
        $resolution = $this->resolveWpPostIdForUrl((string) $item->profile_url);
        $wpPostId = (int) ($resolution['wp_post_id'] ?? 0);

        if ($wpPostId > 0) {
            $client = Client::query()
                ->where('platform_id', (int) $platform->id)
                ->where('client_type', 'escort')
                ->where('wp_post_id', $wpPostId)
                ->first();

            if ($client) {
                $updates = $this->buildItemPayloadFromClient($client, (string) $item->profile_url, $wpPostId);
                if ($wpSync) {
                    $updates = $this->mergeWpPayloadIntoItem(
                        $updates,
                        $this->safeFetchWpProfilePayload($wpSync, $wpPostId)
                    );
                }

                return $updates;
            }

            if ($wpSync) {
                $wpPayload = $this->safeFetchWpProfilePayload($wpSync, $wpPostId);
                if ($wpPayload) {
                    $updates = $this->buildItemPayloadFromWpPayload($wpPayload, $wpPostId);
                    if ($updates !== null) {
                        return $updates;
                    }

                    return [
                        'wp_post_id' => $wpPostId,
                        'status' => 'failed',
                        'error_message' => 'wp_payload_invalid: WordPress profile payload is missing required profile fields.',
                    ];
                }
            }
        }

        $autoMatch = $this->pushCampaignItemMatchService->resolveAutoMatch((int) $platform->id, (string) $item->profile_url);
        if (!empty($autoMatch['candidate']['id'])) {
            $client = $this->pushCampaignItemMatchService->findScopedEscortClient(
                (int) $platform->id,
                (int) $autoMatch['candidate']['id']
            );

            if ($client) {
                $resolvedUrl = (string) ($autoMatch['candidate']['wp_profile_url'] ?? '');
                $updates = $this->buildItemPayloadFromClient(
                    $client,
                    $resolvedUrl !== '' ? $resolvedUrl : (string) $item->profile_url,
                    (int) ($client->wp_post_id ?? 0)
                );

                if ($wpSync && (int) ($client->wp_post_id ?? 0) > 0) {
                    $updates = $this->mergeWpPayloadIntoItem(
                        $updates,
                        $this->safeFetchWpProfilePayload($wpSync, (int) $client->wp_post_id)
                    );
                }

                return $updates;
            }
        }

        $reasonCode = $this->resolveWpFailureCode($resolution, (string) ($autoMatch['reason'] ?? ''));

        return [
            'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
            'status' => 'failed',
            'error_message' => $this->buildWpFailureMessage($reasonCode),
        ];
    }

    private function extractViaPreset(PushCampaignItem $item, Platform $platform, string $phonePrefix): array
    {
        $domain = $this->extractDomain((string) $item->profile_url);

        if (!$domain) {
            return [
                'status' => 'failed',
                'error_message' => 'Profile URL domain could not be parsed.',
            ];
        }

        $preset = ScraperProfilePreset::query()
            ->forDomain($domain)
            ->where('is_active', true)
            ->first();

        if (!$preset) {
            return [
                'status' => 'needs_preset',
                'error_message' => 'No active scraping preset configured for domain: ' . $domain,
            ];
        }

        $payload = $this->fetchHtml((string) $item->profile_url);
        [, $xpath] = $this->parseDom((string) ($payload['html'] ?? ''));

        $name = $this->extractTextBySelector($xpath, (string) $preset->name_selector);
        $age = $this->extractTextBySelector($xpath, (string) $preset->age_selector);
        $phone = $this->extractTextBySelector($xpath, (string) $preset->phone_selector);
        $image = $this->extractImageBySelector($xpath, (string) $preset->image_selector);

        if (!empty($preset->name_regex) && $name) {
            if (preg_match('/' . $preset->name_regex . '/i', $name, $match)) {
                $name = trim((string) ($match[1] ?? $match[0] ?? $name));
            }
        }

        if (!empty($preset->age_regex) && $age) {
            if (preg_match('/' . $preset->age_regex . '/i', $age, $match)) {
                $age = trim((string) ($match[1] ?? $match[0] ?? $age));
            }
        }

        $normalizedPhone = $this->normalizePhone($phone ?: $this->extractPhoneFromText((string) $phone), $phonePrefix);

        if (!$name) {
            return [
                'status' => 'failed',
                'error_message' => 'Preset extraction failed to detect profile name.',
            ];
        }

        return [
            'client_id' => null,
            'profile_name' => $name,
            'profile_phone' => $normalizedPhone,
            'profile_image_url' => $image,
            'profile_age' => $age,
            'status' => 'pending',
            'error_message' => null,
        ];
    }

    private function parseScheduledAt(string $dateLabel, string $timeValue, int $year, Platform $platform): ?Carbon
    {
        $normalizedDate = preg_replace('/(\d+)(st|nd|rd|th)\b/i', '$1', trim($dateLabel)) ?? trim($dateLabel);
        $normalizedTime = trim($timeValue) !== '' ? trim($timeValue) : '00:00:00';
        $timezone = trim((string) ($platform->timezone ?: 'Africa/Nairobi'));

        try {
            return Carbon::parse(sprintf('%s %d %s', $normalizedDate, $year, $normalizedTime), $timezone)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveTimeValue(Worksheet $sheet, int $row): string
    {
        $cell = $sheet->getCell('D' . $row);
        $raw = $cell->getValue();

        if (is_numeric($raw)) {
            try {
                $date = ExcelDate::excelToDateTimeObject((float) $raw);
                return $date->format('H:i:s');
            } catch (\Throwable) {
                // Continue to formatted fallback.
            }
        }

        return $this->normalizeCellText($cell->getFormattedValue());
    }

    private function normalizeCellText($value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function parseWpPostIdFromUrl(string $url): ?int
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/[?&]post_type=escort(?:&|;)?p=(\d+)/i', $trimmed, $match)) {
            return (int) $match[1];
        }

        if (preg_match('/[?&]p=(\d+)/i', $trimmed, $match)) {
            return (int) $match[1];
        }

        if (preg_match('#/(\d+)/?$#', $trimmed, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    /**
     * @return array{
     *     wp_post_id:int|null,
     *     requested_url:string,
     *     effective_url:string|null,
     *     redirected:bool,
     *     http_status:int|null,
     *     link_header:string|null,
     *     error_code:string|null
     * }
     */
    private function resolveWpPostIdForUrl(string $url): array
    {
        $requestedUrl = trim($url);
        $initialPostId = $this->parseWpPostIdFromUrl($requestedUrl);

        $context = [
            'wp_post_id' => $initialPostId,
            'requested_url' => $requestedUrl,
            'effective_url' => null,
            'redirected' => false,
            'http_status' => null,
            'link_header' => null,
            'error_code' => null,
        ];

        if ($initialPostId) {
            return $context;
        }

        try {
            $payload = $this->fetchHtml($requestedUrl);
            $effectiveUrl = trim((string) ($payload['effective_url'] ?? $requestedUrl));
            $linkHeader = trim((string) ($payload['link_header'] ?? ''));

            $resolvedPostId = $this->parseWpPostIdFromUrl($effectiveUrl);
            if (!$resolvedPostId) {
                $resolvedPostId = $this->parseWpPostIdFromLinkHeader($linkHeader);
            }
            if (!$resolvedPostId) {
                $resolvedPostId = $this->parseWpPostIdFromHtmlShortlink((string) ($payload['html'] ?? ''));
            }

            return [
                ...$context,
                'wp_post_id' => $resolvedPostId,
                'effective_url' => $effectiveUrl,
                'redirected' => (bool) ($payload['redirected'] ?? false),
                'http_status' => (int) ($payload['status'] ?? 200),
                'link_header' => $linkHeader !== '' ? $linkHeader : null,
            ];
        } catch (\Throwable $exception) {
            $message = (string) $exception->getMessage();
            $httpStatus = null;

            if (preg_match('/HTTP\s+(\d{3})/i', $message, $match)) {
                $httpStatus = (int) ($match[1] ?? 0);
            }
            if (!$httpStatus && preg_match('/\b(\d{3})\b/', $message, $match)) {
                $httpStatus = (int) ($match[1] ?? 0);
            }

            $isHttp404 = $httpStatus === 404 || str_contains($message, '404');

            return [
                ...$context,
                'http_status' => $httpStatus,
                'error_code' => $isHttp404 ? 'http_404' : 'no_post_id',
            ];
        }
    }

    private function parseWpPostIdFromLinkHeader(string $linkHeader): ?int
    {
        $normalized = trim($linkHeader);
        if ($normalized === '') {
            return null;
        }

        if (preg_match_all('/<([^>]+)>;\s*rel="?shortlink"?/i', $normalized, $matches)) {
            foreach ((array) ($matches[1] ?? []) as $link) {
                $wpPostId = $this->parseWpPostIdFromUrl((string) $link);
                if ($wpPostId) {
                    return $wpPostId;
                }
            }
        }

        return null;
    }

    private function parseWpPostIdFromHtmlShortlink(string $html): ?int
    {
        $normalized = trim($html);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/<link[^>]+rel=["\']shortlink["\'][^>]+href=["\']([^"\']+)["\']/i', $normalized, $match)) {
            return $this->parseWpPostIdFromUrl((string) ($match[1] ?? ''));
        }

        if (preg_match('/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']shortlink["\']/i', $normalized, $match)) {
            return $this->parseWpPostIdFromUrl((string) ($match[1] ?? ''));
        }

        return null;
    }

    private function resolveWpFailureCode(array $resolution, string $autoMatchReason = ''): string
    {
        if ($autoMatchReason === 'ambiguous') {
            return 'ambiguous_match';
        }

        if (($resolution['error_code'] ?? null) === 'http_404' || (int) ($resolution['http_status'] ?? 0) === 404) {
            return 'http_404';
        }

        if ((bool) ($resolution['redirected'] ?? false) && $this->redirectedToHomepage((string) ($resolution['effective_url'] ?? ''))) {
            return 'redirect_home';
        }

        return 'no_post_id';
    }

    private function buildWpFailureMessage(string $reasonCode): string
    {
        return match ($reasonCode) {
            'http_404' => 'http_404: Profile URL returned HTTP 404. Match this item to a CRM profile or update the URL.',
            'redirect_home' => 'redirect_home: URL redirected to homepage. Match this item to a CRM profile.',
            'ambiguous_match' => 'ambiguous_match: Multiple CRM profiles matched this URL. Choose one profile manually.',
            default => 'no_post_id: Could not resolve profile ID from URL. Match this item to a CRM profile or edit the URL.',
        };
    }

    private function redirectedToHomepage(string $effectiveUrl): bool
    {
        $trimmed = trim($effectiveUrl);
        if ($trimmed === '') {
            return false;
        }

        $path = trim((string) parse_url($trimmed, PHP_URL_PATH));
        $query = trim((string) parse_url($trimmed, PHP_URL_QUERY));

        if ($query !== '' && str_contains($query, 'p=')) {
            return false;
        }

        return $path === '' || $path === '/' || strtolower($path) === '/index.php';
    }

    private function safeFetchWpProfilePayload(?WpSyncService $wpSync, int $wpPostId): ?array
    {
        if (!$wpSync || $wpPostId <= 0) {
            return null;
        }

        try {
            $payload = $wpSync->getClientProfile($wpPostId);
            return is_array($payload) ? $payload : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{name:?string,phone:?string,image:?string,age:?string}
     */
    private function extractWpProfileFields(array $payload): array
    {
        $profile = $payload['client'] ?? $payload['data'] ?? $payload;
        $profile = is_array($profile) ? $profile : [];
        $meta = is_array($profile['meta'] ?? null) ? $profile['meta'] : [];

        $name = $profile['name']
            ?? ($profile['post']['title'] ?? null)
            ?? null;

        $phone = $meta['phone']
            ?? ($profile['phone'] ?? null)
            ?? null;

        $image = $profile['main_image_url']
            ?? ($profile['featured_image'] ?? null)
            ?? ($meta['main_image_url'] ?? null)
            ?? null;

        $age = $meta['age']
            ?? ($meta['profile_age'] ?? null)
            ?? null;

        return [
            'name' => $name ? trim((string) $name) : null,
            'phone' => $phone ? trim((string) $phone) : null,
            'image' => $image ? trim((string) $image) : null,
            'age' => $age ? trim((string) $age) : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildItemPayloadFromWpPayload(array $payload, int $wpPostId): ?array
    {
        $fields = $this->extractWpProfileFields($payload);
        if (empty($fields['name'])) {
            return null;
        }

        return [
            'client_id' => null,
            'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
            'profile_name' => $fields['name'],
            'profile_phone' => $fields['phone'],
            'profile_image_url' => $fields['image'],
            'profile_age' => $fields['age'],
            'status' => 'pending',
            'error_message' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildItemPayloadFromClient(Client $client, string $profileUrl, int $wpPostId = 0): array
    {
        $clientWpPostId = $wpPostId > 0 ? $wpPostId : (int) ($client->wp_post_id ?? 0);

        return [
            'client_id' => (int) $client->id,
            'wp_post_id' => $clientWpPostId > 0 ? $clientWpPostId : null,
            'profile_url' => $profileUrl,
            'profile_name' => $client->name,
            'profile_phone' => $client->phone_normalized,
            'profile_image_url' => $client->main_image_url,
            'status' => 'pending',
            'error_message' => null,
        ];
    }

    /**
     * @param array<string, mixed> $updates
     * @return array<string, mixed>
     */
    private function mergeWpPayloadIntoItem(array $updates, ?array $payload): array
    {
        if (!$payload) {
            return $updates;
        }

        $fields = $this->extractWpProfileFields($payload);
        if (!empty($fields['name']) && empty($updates['profile_name'])) {
            $updates['profile_name'] = $fields['name'];
        }
        if (!empty($fields['phone'])) {
            $updates['profile_phone'] = $fields['phone'];
        }
        if (!empty($fields['image'])) {
            $updates['profile_image_url'] = $fields['image'];
        }
        if (!empty($fields['age'])) {
            $updates['profile_age'] = $fields['age'];
        }

        return $updates;
    }

    private function hasWpIntegration(Platform $platform): bool
    {
        return !empty($platform->wp_api_url)
            && !empty($platform->wp_api_user)
            && !empty($platform->wp_api_password);
    }

    private function extractDomain(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);
        if (!is_string($host) || trim($host) === '') {
            return null;
        }

        return strtolower(preg_replace('/^www\./i', '', trim($host)) ?? trim($host));
    }

    private function extractTextBySelector(\DOMXPath $xpath, string $selector): ?string
    {
        if (trim($selector) === '') {
            return null;
        }

        $node = $this->queryCss($xpath, $selector)[0] ?? null;
        if (!$node) {
            return null;
        }

        $text = $this->nodeText($node);

        return $text !== '' ? $text : null;
    }

    private function extractImageBySelector(\DOMXPath $xpath, string $selector): ?string
    {
        if (trim($selector) === '') {
            return null;
        }

        $node = $this->queryCss($xpath, $selector)[0] ?? null;
        if (!$node) {
            return null;
        }

        $src = trim((string) ($node->attributes?->getNamedItem('src')?->nodeValue ?? ''));
        if ($src !== '') {
            return $src;
        }

        $content = trim((string) ($node->attributes?->getNamedItem('content')?->nodeValue ?? ''));

        return $content !== '' ? $content : null;
    }
}
