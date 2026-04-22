<?php

namespace App\Services\PushCampaign;

use App\Models\Client;
use App\Models\Platform;
use App\Models\PushCampaignItem;
use App\Models\ScraperProfilePreset;
use App\Services\WordPressProfileUrlResolver;
use App\Services\WpSyncService;
use App\Support\DomParserTrait;
use App\Support\MarketTimezone;
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
        $resolutionFailureCode = $this->resolveWpFailureCode($resolution);
        $redirectedHome = $resolutionFailureCode === 'redirect_home';
        $wpPostId = $redirectedHome ? 0 : (int) ($resolution['wp_post_id'] ?? 0);
        $wpPayloadInvalid = false;

        if ($wpPostId > 0) {
            $client = Client::query()
                ->where('platform_id', (int) $platform->id)
                ->where('client_type', 'escort')
                ->where('wp_post_id', $wpPostId)
                ->first();

            if ($client) {
                $updates = $this->buildItemPayloadFromClient($client, (string) $item->profile_url, $wpPostId);
                if ($wpSync) {
                    $wpPayload = $this->safeFetchWpProfilePayload($wpSync, $wpPostId);
                    $updates = $this->mergeWpPayloadIntoItem(
                        $updates,
                        $wpPayload,
                        $item,
                        $platform
                    );

                    $updates = $this->mergeWpMediaIntoItem(
                        $updates,
                        $this->safeFetchWpMediaPayload($wpSync, $wpPostId)
                    );
                }

                return $updates;
            }

            if ($wpSync) {
                $wpPayload = $this->safeFetchWpProfilePayload($wpSync, $wpPostId);
                if ($wpPayload) {
                    $updates = $this->buildItemPayloadFromWpPayload($wpPayload, $wpPostId, $item, $platform);
                    if ($updates !== null) {
                        $updates = $this->mergeWpMediaIntoItem(
                            $updates,
                            $this->safeFetchWpMediaPayload($wpSync, $wpPostId)
                        );
                        return $updates;
                    }

                    $wpPayloadInvalid = true;
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
                    $wpPayload = $this->safeFetchWpProfilePayload($wpSync, (int) $client->wp_post_id);
                    $updates = $this->mergeWpPayloadIntoItem(
                        $updates,
                        $wpPayload,
                        $item,
                        $platform
                    );
                    $updates = $this->mergeWpMediaIntoItem(
                        $updates,
                        $this->safeFetchWpMediaPayload($wpSync, (int) $client->wp_post_id)
                    );
                }

                return $updates;
            }
        }

        $reasonCode = $wpPayloadInvalid
            ? 'wp_payload_invalid'
            : $this->resolveWpFailureCode($resolution, (string) ($autoMatch['reason'] ?? ''));

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
        $timezone = MarketTimezone::resolve($platform->timezone, config('app.timezone', 'UTC'));

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
        return app(WordPressProfileUrlResolver::class)->parseWpPostIdFromUrl($url);
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
            if (!$resolvedPostId) {
                $resolvedPostId = $this->parseWpPostIdFromHtmlMarkers((string) ($payload['html'] ?? ''));
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
        return app(WordPressProfileUrlResolver::class)->parseWpPostIdFromLinkHeader($linkHeader);
    }

    private function parseWpPostIdFromHtmlShortlink(string $html): ?int
    {
        return app(WordPressProfileUrlResolver::class)->parseWpPostIdFromHtmlShortlink($html);
    }

    private function parseWpPostIdFromHtmlMarkers(string $html): ?int
    {
        return app(WordPressProfileUrlResolver::class)->parseWpPostIdFromHtmlMarkers($html);
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
            'redirect_home' => 'redirect_home: Imported profile URL redirected to homepage, which usually means the slug is stale or wrong. Review the suggested CRM match or update the URL.',
            'ambiguous_match' => 'ambiguous_match: Multiple CRM profiles matched this URL. Choose one profile manually.',
            'wp_payload_invalid' => 'wp_payload_invalid: WordPress returned a page, but it did not look like a valid profile. Review the suggested CRM match or update the URL.',
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
     * @return array<int, array<string, mixed>>|null
     */
    private function safeFetchWpMediaPayload(?WpSyncService $wpSync, int $wpPostId): ?array
    {
        if (!$wpSync || $wpPostId <= 0) {
            return null;
        }

        try {
            $payload = $wpSync->getClientMedia($wpPostId);
            return is_array($payload) ? $payload : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{name:?string,phone:?string,image:?string,age_value:?string,birthday:?string}
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

        $birthday = $meta['birthday']
            ?? ($profile['birthday'] ?? null)
            ?? null;

        $city = $profile['city']
            ?? ($meta['city'] ?? null)
            ?? null;

        return [
            'name' => $name ? trim((string) $name) : null,
            'phone' => $phone ? trim((string) $phone) : null,
            'image' => $image ? trim((string) $image) : null,
            'age_value' => $age ? trim((string) $age) : null,
            'birthday' => $birthday ? trim((string) $birthday) : null,
            'city' => $city ? trim((string) $city) : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildItemPayloadFromWpPayload(array $payload, int $wpPostId, PushCampaignItem $item, Platform $platform): ?array
    {
        $fields = $this->extractWpProfileFields($payload);
        if (empty($fields['name'])) {
            return null;
        }

        $profileAge = $fields['age_value'] ?? null;
        if (!$profileAge && !empty($fields['birthday'])) {
            $profileAge = $this->deriveAgeFromBirthday(
                (string) $fields['birthday'],
                $this->resolveItemAgeReferenceDate($item, $platform),
                MarketTimezone::resolve($platform->timezone, config('app.timezone', 'UTC'))
            );
        }

        return [
            'client_id' => null,
            'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
            'profile_name' => $fields['name'],
            'profile_city' => $fields['city'] ?? null,
            'profile_phone' => $fields['phone'],
            'profile_image_url' => $fields['image'],
            'profile_age' => $profileAge,
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
            'profile_city' => $client->city ?: null,
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
    private function mergeWpPayloadIntoItem(array $updates, ?array $payload, PushCampaignItem $item, Platform $platform): array
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
        if (!empty($fields['city']) && empty($updates['profile_city'])) {
            $updates['profile_city'] = $fields['city'];
        }
        if (!empty($fields['age_value'])) {
            $updates['profile_age'] = $fields['age_value'];
        } elseif (empty($updates['profile_age']) && !empty($fields['birthday'])) {
            $derivedAge = $this->deriveAgeFromBirthday(
                (string) $fields['birthday'],
                $this->resolveItemAgeReferenceDate($item, $platform),
                MarketTimezone::resolve($platform->timezone, config('app.timezone', 'UTC'))
            );
            if ($derivedAge !== null) {
                $updates['profile_age'] = $derivedAge;
            }
        }

        return $updates;
    }

    /**
     * @param array<string, mixed> $updates
     * @param array<string, mixed>|null $mediaPayload
     * @return array<string, mixed>
     */
    private function mergeWpMediaIntoItem(array $updates, ?array $mediaPayload): array
    {
        if (!empty($updates['profile_image_url'])) {
            return $updates;
        }

        $mediaItems = $this->normalizeWpMediaItems($mediaPayload);
        if (empty($mediaItems)) {
            return $updates;
        }

        $recommended = $this->pickRecommendedMedia($mediaItems);
        if ($recommended && !empty($recommended['url'])) {
            $updates['profile_image_url'] = (string) $recommended['url'];
        }

        return $updates;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<int, array{id:int,url:string,is_main:bool,mime_type:?string}>
     */
    private function normalizeWpMediaItems(?array $payload): array
    {
        if (!$payload) {
            return [];
        }

        $rows = data_get($payload, 'data');
        if (!is_array($rows)) {
            $rows = array_is_list($payload) ? $payload : [];
        }

        return collect($rows)
            ->map(function ($media): array {
                $row = is_array($media) ? $media : [];
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'url' => trim((string) ($row['url'] ?? '')),
                    'is_main' => (bool) ($row['is_main'] ?? false),
                    'mime_type' => isset($row['mime_type']) ? trim((string) $row['mime_type']) : null,
                ];
            })
            ->filter(fn(array $media): bool => (int) ($media['id'] ?? 0) > 0
                && ($media['url'] ?? '') !== ''
                && $this->isImageMedia($media))
            ->values()
            ->all();
    }

    private function isImageMedia(array $media): bool
    {
        $mimeType = strtolower(trim((string) ($media['mime_type'] ?? '')));
        if ($mimeType !== '') {
            return str_starts_with($mimeType, 'image/');
        }

        $url = strtolower(trim((string) ($media['url'] ?? '')));
        return (bool) preg_match('/\.(jpe?g|png|webp)(?:$|[?#])/', $url);
    }

    /**
     * @param array<int, array{id:int,url:string,is_main:bool,mime_type:?string}> $mediaItems
     * @return array{id:int,url:string,is_main:bool}|null
     */
    private function pickRecommendedMedia(array $mediaItems): ?array
    {
        if (empty($mediaItems)) {
            return null;
        }

        $main = collect($mediaItems)->first(fn(array $item): bool => (bool) ($item['is_main'] ?? false));
        if ($main) {
            return $main;
        }

        return $mediaItems[0] ?? null;
    }

    private function resolveItemAgeReferenceDate(PushCampaignItem $item, Platform $platform): Carbon
    {
        $timezone = MarketTimezone::resolve($platform->timezone, config('app.timezone', 'UTC'));
        $scheduledAt = $item->scheduled_at;

        if ($scheduledAt instanceof Carbon) {
            return $scheduledAt->copy()->setTimezone($timezone);
        }

        if (!empty($scheduledAt)) {
            try {
                return Carbon::parse((string) $scheduledAt, 'UTC')->setTimezone($timezone);
            } catch (\Throwable) {
                // Ignore invalid values and fallback to now.
            }
        }

        return now($timezone);
    }

    private function deriveAgeFromBirthday(string $birthday, Carbon $referenceDate, string $timezone): ?string
    {
        $normalizedBirthday = trim($birthday);
        if ($normalizedBirthday === '') {
            return null;
        }

        try {
            $birthDate = Carbon::parse($normalizedBirthday, $timezone)->startOfDay();
            $reference = $referenceDate->copy()->setTimezone($timezone)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($birthDate->greaterThan($reference)) {
            return null;
        }

        $years = $birthDate->diffInYears($reference);
        if ($years < 0 || $years > 120) {
            return null;
        }

        return (string) $years;
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
