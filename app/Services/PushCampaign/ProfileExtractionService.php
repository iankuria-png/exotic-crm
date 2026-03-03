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

            $updates = [
                'wp_post_id' => $this->parseWpPostIdFromUrl((string) $item->profile_url),
            ];

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
        $wpPostId = $this->parseWpPostIdFromUrl((string) $item->profile_url);

        if ($wpPostId) {
            $client = Client::query()
                ->where('platform_id', (int) $platform->id)
                ->where('wp_post_id', $wpPostId)
                ->first();

            if ($client) {
                return [
                    'client_id' => $client->id,
                    'profile_name' => $client->name,
                    'profile_phone' => $client->phone_normalized,
                    'profile_image_url' => $client->main_image_url,
                    'profile_age' => null,
                    'status' => 'pending',
                    'error_message' => null,
                ];
            }
        }

        if (!$wpSync || !$wpPostId) {
            return [
                'status' => 'failed',
                'error_message' => 'Profile could not be resolved via WordPress integration.',
            ];
        }

        $payload = $wpSync->getClientProfile($wpPostId);
        $profile = $payload['client'] ?? $payload['data'] ?? $payload;
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

        if (!$name) {
            return [
                'status' => 'failed',
                'error_message' => 'WordPress profile payload missing required name field.',
            ];
        }

        return [
            'client_id' => null,
            'profile_name' => $name,
            'profile_phone' => $phone,
            'profile_image_url' => $image,
            'profile_age' => $age,
            'status' => 'pending',
            'error_message' => null,
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

        if (preg_match('/[?&]p=(\d+)/i', $trimmed, $match)) {
            return (int) $match[1];
        }

        if (preg_match('#/(\d+)/?$#', $trimmed, $match)) {
            return (int) $match[1];
        }

        return null;
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
