<?php

namespace App\Services;

use App\Models\Client;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class SupportBoardProfileSyncService
{
    private const FIELD_LABELS = [
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'city' => 'City',
    ];

    private const DETAIL_LABELS = [
        'browser' => 'Browser',
        'browser_language' => 'Browser language',
        'city' => 'City',
        'country' => 'Country',
        'country_code' => 'Country code',
        'currency' => 'Currency',
        'current_url' => 'Current URL',
        'device_type' => 'Device type',
        'email' => 'Email',
        'host' => 'Host',
        'ip' => 'IP',
        'landing_language' => 'Landing language',
        'landing_path' => 'Landing path',
        'landing_url' => 'Landing URL',
        'language' => 'Language',
        'location' => 'Location',
        'os' => 'OS',
        'phone' => 'Phone',
        'referrer' => 'Referrer',
        'time_zone' => 'Timezone',
        'widget_language' => 'Widget language',
    ];

    private const COUNTRY_NAMES = [
        'ET' => 'Ethiopia',
        'GH' => 'Ghana',
        'KE' => 'Kenya',
        'NG' => 'Nigeria',
        'RW' => 'Rwanda',
        'TZ' => 'Tanzania',
        'UG' => 'Uganda',
        'ZA' => 'South Africa',
    ];

    public function __construct(
        private readonly Client $client,
        private readonly SupportBoardService $supportBoardService
    ) {
    }

    public function buildProfilePayload(): array
    {
        $context = $this->loadContext();

        return [
            'configured' => true,
            'matched' => true,
            'matched_by' => $context['matched_by'],
            'sb_user' => $context['sb_user'],
            'details' => $context['details'],
            'detail_map' => $context['detail_map'],
            'primary_details' => $this->primaryDetails($context['detail_map']),
            'secondary_details' => $this->secondaryDetails($context['details']),
            'suggestions' => $context['suggestions'],
        ];
    }

    public function preview(string $direction, string $mode, array $fields): array
    {
        $context = $this->loadContext();
        $fields = $this->normalizeFields($fields);
        $rows = collect($fields)
            ->map(fn (string $field) => $this->buildPreviewRow($field, $direction, $mode, $context))
            ->values()
            ->all();

        $warnings = $this->buildWarnings($fields, $context['suggestions']);

        return [
            'direction' => $direction,
            'mode' => $mode,
            'fields' => $fields,
            'rows' => $rows,
            'counts' => [
                'applyable' => count(array_filter($rows, fn (array $row) => in_array($row['outcome'], ['fill', 'update'], true))),
                'conflicts' => count(array_filter($rows, fn (array $row) => $row['outcome'] === 'conflict')),
                'same' => count(array_filter($rows, fn (array $row) => $row['outcome'] === 'same')),
                'unavailable' => count(array_filter($rows, fn (array $row) => $row['outcome'] === 'unavailable')),
            ],
            'warnings' => $warnings,
            'suggestions' => $context['suggestions'],
        ];
    }

    public function apply(string $direction, string $mode, array $fields): array
    {
        $preview = $this->preview($direction, $mode, $fields);
        $applyableRows = collect($preview['rows'])
            ->filter(fn (array $row) => in_array($row['outcome'], ['fill', 'update'], true))
            ->values();

        if ($applyableRows->isEmpty()) {
            return [
                'preview' => $preview,
                'applied_count' => 0,
                'changed_fields' => [],
                'before' => [],
                'after' => [],
            ];
        }

        $beforeState = $this->captureBeforeState($direction, $applyableRows->all());

        if ($direction === 'support_board_to_crm') {
            $this->applySupportBoardToCrm($applyableRows->all());
        } else {
            $this->applyCrmToSupportBoard($applyableRows->all());
        }

        SupportBoardService::clearResolveCache($this->client);
        $this->client->refresh()->loadMissing('platform', 'assignedAgent');

        $afterState = $this->captureAfterState($direction, $applyableRows->all());

        return [
            'preview' => $preview,
            'applied_count' => $applyableRows->count(),
            'changed_fields' => $applyableRows->pluck('field')->values()->all(),
            'before' => $beforeState,
            'after' => $afterState,
        ];
    }

    private function loadContext(): array
    {
        $resolved = $this->supportBoardService->resolveClient($this->client);
        $sbUserId = (int) ($resolved['sb_user']['id'] ?? $this->client->sb_user_id ?? 0);

        if (!($resolved['matched'] ?? false) || $sbUserId <= 0) {
            throw new RuntimeException('Client is not linked to Support Board.');
        }

        $sbUser = $this->supportBoardService->getUser($sbUserId, true);
        if (!$sbUser) {
            throw new RuntimeException('Support Board user details could not be loaded.');
        }

        $detailPayload = $this->supportBoardService->normalizeUserDetails($sbUser['details'] ?? []);
        if (empty($detailPayload['items'])) {
            $detailPayload = $this->supportBoardService->normalizeUserDetails(
                $this->supportBoardService->getUserExtra($sbUserId)
            );
        }

        return [
            'matched_by' => $resolved['matched_by'] ?? $this->client->sb_matched_by,
            'sb_user' => $sbUser,
            'details' => $detailPayload['items'],
            'detail_map' => $detailPayload['map'],
            'suggestions' => $this->buildSuggestions($detailPayload['map']),
        ];
    }

    private function normalizeFields(array $fields): array
    {
        return collect($fields)
            ->filter(fn ($field) => is_string($field) && array_key_exists($field, self::FIELD_LABELS))
            ->values()
            ->unique()
            ->all();
    }

    private function buildPreviewRow(string $field, string $direction, string $mode, array $context): array
    {
        [$sourceValue, $sourceLabel, $sourceNote] = $this->resolveFieldValue($field, $direction, $context, true);
        [$targetValue, $targetLabel] = $this->resolveFieldValue($field, $direction, $context, false);

        $normalizedSource = $this->normalizeComparableValue($field, $sourceValue);
        $normalizedTarget = $this->normalizeComparableValue($field, $targetValue);

        $outcome = 'same';
        $reason = 'Values already match.';

        if ($normalizedSource === null || $normalizedSource === '') {
            $outcome = 'unavailable';
            $reason = $this->unavailableReason($field);
        } elseif ($normalizedTarget === $normalizedSource) {
            $outcome = 'same';
            $reason = 'Source and target already match.';
        } elseif ($normalizedTarget === null || $normalizedTarget === '') {
            $outcome = 'fill';
            $reason = 'Target is blank, so this value can be filled safely.';
        } elseif ($mode === 'overwrite') {
            $outcome = 'update';
            $reason = 'Overwrite mode allows replacing the current target value.';
        } else {
            $outcome = 'conflict';
            $reason = 'Target already has a different value. Switch to overwrite mode to replace it.';
        }

        return [
            'field' => $field,
            'label' => self::FIELD_LABELS[$field],
            'source_label' => $sourceLabel,
            'source_value' => $sourceValue,
            'source_note' => $sourceNote,
            'target_label' => $targetLabel,
            'target_value' => $targetValue,
            'next_value' => $sourceValue,
            'outcome' => $outcome,
            'reason' => $reason,
        ];
    }

    private function resolveFieldValue(string $field, string $direction, array $context, bool $source): array
    {
        $fromSupportBoard = ($direction === 'support_board_to_crm' && $source) || ($direction === 'crm_to_support_board' && !$source);
        if ($fromSupportBoard) {
            return $this->supportBoardFieldValue($field, $context);
        }

        return $this->crmFieldValue($field);
    }

    private function supportBoardFieldValue(string $field, array $context): array
    {
        $detailMap = $context['detail_map'];
        $suggestions = $context['suggestions'];
        $user = $context['sb_user'];

        return match ($field) {
            'name' => [$user['full_name'] ?: null, 'Support Board profile', null],
            'email' => [$user['email'] ?: null, 'Support Board profile', null],
            'phone' => [Arr::get($detailMap, 'phone.value'), 'Support Board detail', null],
            'city' => [
                Arr::get($detailMap, 'city.value') ?: Arr::get($suggestions, 'city.value'),
                Arr::get($detailMap, 'city.value') ? 'Support Board detail' : 'Location-derived suggestion',
                Arr::get($detailMap, 'city.value') ? null : Arr::get($suggestions, 'city.note'),
            ],
            default => [null, 'Support Board', null],
        };
    }

    private function crmFieldValue(string $field): array
    {
        return match ($field) {
            'name' => [$this->client->name ?: null, 'CRM client profile', null],
            'email' => [$this->client->email ?: null, 'CRM client profile', null],
            'phone' => [$this->client->phone_normalized ?: null, 'CRM client profile', null],
            'city' => [$this->client->city ?: null, 'CRM client profile', null],
            default => [null, 'CRM client profile', null],
        };
    }

    private function normalizeComparableValue(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return match ($field) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ? Str::lower($value) : null,
            'phone' => $this->normalizePhoneForComparison($value),
            default => preg_replace('/\s+/', ' ', Str::lower($value)) ?: null,
        };
    }

    private function buildSuggestions(array $detailMap): array
    {
        $location = trim((string) Arr::get($detailMap, 'location.value', ''));
        $countryCode = trim((string) Arr::get($detailMap, 'country_code.value', ''));
        $countryName = $this->resolveCountryName($countryCode, $location);
        $city = $this->parseCityFromLocation($location);
        $currentMarket = trim((string) ($this->client->platform?->country ?: ''));

        return [
            'market' => $countryName ? [
                'country_code' => $countryCode !== '' ? strtoupper($countryCode) : null,
                'country_name' => $countryName,
                'current_market' => $currentMarket !== '' ? $currentMarket : null,
                'matches_current_market' => $currentMarket !== '' && $this->sameCountry($countryName, $currentMarket),
                'note' => 'Country hints can guide market review, but CRM market stays unchanged unless someone updates it manually.',
            ] : null,
            'city' => $city ? [
                'value' => $city,
                'note' => 'Derived from Support Board location and only applied if city is selected in the sync preview.',
            ] : null,
            'location' => $location !== '' ? $location : null,
        ];
    }

    private function buildWarnings(array $fields, array $suggestions): array
    {
        $warnings = [];

        $marketSuggestion = $suggestions['market'] ?? null;
        if ($marketSuggestion && !($marketSuggestion['matches_current_market'] ?? false)) {
            $warnings[] = [
                'type' => 'market_review',
                'message' => sprintf(
                    'Support Board hints suggest %s while this CRM client is currently in %s. Market will not be changed automatically.',
                    $marketSuggestion['country_name'],
                    $marketSuggestion['current_market'] ?: 'the current market'
                ),
            ];
        }

        if (in_array('city', $fields, true) && empty($suggestions['city']['value'])) {
            $warnings[] = [
                'type' => 'city_unavailable',
                'message' => 'City sync is optional, but there is no clean city value available from Support Board for this profile.',
            ];
        }

        return $warnings;
    }

    private function applySupportBoardToCrm(array $rows): void
    {
        $updates = [];

        foreach ($rows as $row) {
            if (!in_array($row['outcome'], ['fill', 'update'], true)) {
                continue;
            }

            $nextValue = $row['next_value'];
            if ($nextValue === null || trim((string) $nextValue) === '') {
                continue;
            }

            switch ($row['field']) {
                case 'name':
                    $updates['name'] = Str::limit(trim((string) $nextValue), 255, '');
                    break;
                case 'email':
                    if (filter_var($nextValue, FILTER_VALIDATE_EMAIL)) {
                        $updates['email'] = Str::lower(trim((string) $nextValue));
                    }
                    break;
                case 'phone':
                    $normalizedPhone = PhoneNormalizer::normalize(
                        (string) $nextValue,
                        (string) ($this->client->platform?->phone_prefix ?: '254')
                    );
                    if ($normalizedPhone) {
                        $updates['phone_normalized'] = $normalizedPhone;
                    }
                    break;
                case 'city':
                    $updates['city'] = Str::limit(trim((string) $nextValue), 100, '');
                    break;
            }
        }

        if (!empty($updates)) {
            $this->client->fill($updates)->save();
        }
    }

    private function applyCrmToSupportBoard(array $rows): void
    {
        $context = $this->loadContext();
        $sbUserId = (int) ($context['sb_user']['id'] ?? 0);
        if ($sbUserId <= 0) {
            throw new RuntimeException('Support Board user ID is missing.');
        }

        $settings = [];
        $settingsExtra = [];

        foreach ($rows as $row) {
            if (!in_array($row['outcome'], ['fill', 'update'], true)) {
                continue;
            }

            $nextValue = trim((string) ($row['next_value'] ?? ''));
            if ($nextValue === '') {
                continue;
            }

            switch ($row['field']) {
                case 'name':
                    ['first_name' => $firstName, 'last_name' => $lastName] = $this->splitName($nextValue);
                    if ($firstName !== '') {
                        $settings['first_name'] = $firstName;
                    }
                    $settings['last_name'] = $lastName;
                    break;
                case 'email':
                    if (filter_var($nextValue, FILTER_VALIDATE_EMAIL)) {
                        $settings['email'] = Str::lower($nextValue);
                    }
                    break;
                case 'phone':
                    $settingsExtra['phone'] = [$nextValue, $this->detailLabel('phone', $context['detail_map'])];
                    break;
                case 'city':
                    $settingsExtra['city'] = [$nextValue, $this->detailLabel('city', $context['detail_map'])];
                    break;
            }
        }

        if (!empty($settings) || !empty($settingsExtra)) {
            $this->supportBoardService->updateUser($sbUserId, $settings, $settingsExtra);
        }
    }

    private function captureBeforeState(string $direction, array $rows): array
    {
        $state = [];

        foreach ($rows as $row) {
            $state[$row['field']] = $row['target_value'];
        }

        return $state;
    }

    private function captureAfterState(string $direction, array $rows): array
    {
        $state = [];

        foreach ($rows as $row) {
            $state[$row['field']] = $direction === 'support_board_to_crm'
                ? match ($row['field']) {
                    'name' => $this->client->name,
                    'email' => $this->client->email,
                    'phone' => $this->client->phone_normalized,
                    'city' => $this->client->city,
                    default => null,
                }
                : $row['next_value'];
        }

        return $state;
    }

    private function primaryDetails(array $detailMap): array
    {
        $ordered = ['phone', 'country_code', 'location', 'currency', 'current_url', 'time_zone', 'browser_language'];

        return collect($ordered)
            ->map(fn (string $slug) => Arr::get($detailMap, $slug))
            ->filter(fn ($detail) => is_array($detail) && filled($detail['value'] ?? null))
            ->values()
            ->all();
    }

    private function secondaryDetails(array $details): array
    {
        return collect($details)
            ->reject(fn (array $detail) => in_array((string) ($detail['slug'] ?? ''), ['phone', 'country_code', 'location', 'currency', 'current_url', 'time_zone', 'browser_language'], true))
            ->values()
            ->all();
    }

    private function resolveCountryName(string $countryCode, string $location): ?string
    {
        $countryCode = strtoupper(trim($countryCode));
        if ($countryCode !== '' && isset(self::COUNTRY_NAMES[$countryCode])) {
            return self::COUNTRY_NAMES[$countryCode];
        }

        if ($location !== '' && str_contains($location, ',')) {
            $parts = array_values(array_filter(array_map('trim', explode(',', $location))));
            $candidate = end($parts) ?: null;
            if ($candidate) {
                return Str::title(Str::lower($candidate));
            }
        }

        return null;
    }

    private function parseCityFromLocation(string $location): ?string
    {
        if ($location === '') {
            return null;
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $location))));
        $city = $parts[0] ?? null;

        if (!$city) {
            return null;
        }

        return Str::limit($city, 100, '');
    }

    private function sameCountry(string $left, string $right): bool
    {
        return Str::lower(trim($left)) === Str::lower(trim($right));
    }

    private function splitName(string $fullName): array
    {
        $fullName = preg_replace('/\s+/', ' ', trim($fullName)) ?: '';
        if ($fullName === '') {
            return ['first_name' => '', 'last_name' => ''];
        }

        $parts = explode(' ', $fullName);
        $firstName = array_shift($parts) ?: '';

        return [
            'first_name' => Str::limit($firstName, 255, ''),
            'last_name' => Str::limit(trim(implode(' ', $parts)), 255, ''),
        ];
    }

    private function detailLabel(string $slug, array $detailMap): string
    {
        return Arr::get($detailMap, "{$slug}.name")
            ?: (self::DETAIL_LABELS[$slug] ?? Str::title(str_replace('_', ' ', $slug)));
    }

    private function normalizePhoneForComparison(string $value): ?string
    {
        $normalized = PhoneNormalizer::normalize(
            $value,
            (string) ($this->client->platform?->phone_prefix ?: '254')
        );

        if ($normalized) {
            return preg_replace('/\D+/', '', $normalized) ?: null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        return $digits !== '' ? $digits : null;
    }

    private function unavailableReason(string $field): string
    {
        return match ($field) {
            'email' => 'No valid email address is available on the selected source side.',
            'phone' => 'No usable phone number is available on the selected source side.',
            default => 'No usable source value is available for this field.',
        };
    }
}
