<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Platform;
use App\Models\TimelineEvent;
use Illuminate\Support\Facades\Log;

class LeadImportService
{
    public function __construct(
        private readonly LeadAssignmentService $leadAssignmentService
    ) {
    }

    public function importPlatform(Platform $platform, bool $dryRun = false, int $perPage = 100): array
    {
        if (!$platform->wp_api_url || !$platform->wp_api_user || !$platform->wp_api_password) {
            return [
                'platform_id' => $platform->id,
                'platform_name' => $platform->name,
                'scanned' => 0,
                'eligible' => 0,
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'unassigned' => 0,
                'errors' => ['WordPress sync credentials are not configured.'],
            ];
        }

        $wpSync = new WpSyncService($platform);

        $stats = [
            'platform_id' => $platform->id,
            'platform_name' => $platform->name,
            'scanned' => 0,
            'eligible' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'unassigned' => 0,
            'errors' => [],
        ];

        $page = 1;
        $totalPages = 1;

        do {
            $response = $wpSync->getClients($page, $perPage);
            $records = $response['data'] ?? [];
            $totalPages = (int) ($response['pages'] ?? 1);

            foreach ($records as $wpClient) {
                $stats['scanned']++;

                if (empty($wpClient['needs_payment'])) {
                    continue;
                }

                $stats['eligible']++;

                try {
                    $prepared = $this->prepareLeadPayload($platform, $wpClient);
                    $outcome = $this->upsertLead($platform, $prepared, $dryRun);

                    if (isset($stats[$outcome])) {
                        $stats[$outcome]++;
                    }

                    if ($prepared['assigned_to'] === null) {
                        $stats['unassigned']++;
                    }
                } catch (\Throwable $exception) {
                    $stats['errors'][] = sprintf(
                        'Lead import failed for wp_post_id=%s: %s',
                        $wpClient['wp_post_id'] ?? 'unknown',
                        $exception->getMessage()
                    );

                    Log::error('Lead import row failed', [
                        'platform_id' => $platform->id,
                        'wp_post_id' => $wpClient['wp_post_id'] ?? null,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $page++;
        } while ($page <= $totalPages);

        return $stats;
    }

    private function upsertLead(Platform $platform, array $payload, bool $dryRun): string
    {
        $query = Lead::query()->where('platform_id', $platform->id);

        if (!empty($payload['wp_post_id'])) {
            $query->where('wp_post_id', $payload['wp_post_id']);
        } elseif (!empty($payload['wp_user_id'])) {
            $query->where('wp_user_id', $payload['wp_user_id']);
        } elseif (!empty($payload['phone_normalized'])) {
            $query->where('phone_normalized', $payload['phone_normalized']);
        } else {
            $query->whereRaw('1 = 0');
        }

        $existing = $query->first();

        if ($existing) {
            $before = $existing->only([
                'name',
                'phone_normalized',
                'email',
                'assigned_to',
                'wp_post_id',
                'wp_user_id',
            ]);

            $nextAssignedTo = $this->leadAssignmentService->assignOwnerId(
                (int) $platform->id,
                $payload,
                $existing->assigned_to
            );

            $attributes = [
                'wp_user_id' => $payload['wp_user_id'],
                'wp_post_id' => $payload['wp_post_id'],
                'name' => $payload['name'],
                'phone_normalized' => $payload['phone_normalized'],
                'email' => $payload['email'],
                'source' => 'import',
                'assigned_to' => $nextAssignedTo,
            ];

            if ($dryRun) {
                return $this->hasAnyDifference($before, $attributes) ? 'updated' : 'unchanged';
            }

            $existing->fill($attributes);
            $changed = $existing->isDirty();
            $existing->save();

            if ($changed) {
                TimelineEvent::create([
                    'platform_id' => $platform->id,
                    'entity_type' => 'lead',
                    'entity_id' => $existing->id,
                    'event_type' => 'lead_import_refreshed',
                    'actor_id' => null,
                    'content' => [
                        'before' => $before,
                        'after' => $existing->only([
                            'name',
                            'phone_normalized',
                            'email',
                            'assigned_to',
                            'wp_post_id',
                            'wp_user_id',
                        ]),
                    ],
                    'created_at' => now(),
                ]);
            }

            return $changed ? 'updated' : 'unchanged';
        }

        $payload['status'] = 'new';
        $payload['source'] = 'import';
        $payload['assigned_to'] = $this->leadAssignmentService->assignOwnerId((int) $platform->id, $payload);

        if ($dryRun) {
            return 'created';
        }

        $lead = Lead::create($payload);

        TimelineEvent::create([
            'platform_id' => $platform->id,
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'event_type' => 'lead_imported',
            'actor_id' => null,
            'content' => [
                'source' => 'needs_payment_import',
                'assigned_to' => $lead->assigned_to,
                'wp_post_id' => $lead->wp_post_id,
            ],
            'created_at' => now(),
        ]);

        return 'created';
    }

    private function prepareLeadPayload(Platform $platform, array $wpClient): array
    {
        $phone = $this->normalizePhone(
            (string) ($wpClient['phone'] ?? ''),
            (string) ($platform->phone_prefix ?? '254')
        );

        $payload = [
            'platform_id' => $platform->id,
            'wp_user_id' => $wpClient['wp_user_id'] ?? null,
            'wp_post_id' => $wpClient['wp_post_id'] ?? null,
            'name' => $this->limitText($wpClient['name'] ?? null, 255),
            'phone_normalized' => $this->limitText($phone, 20),
            'email' => $this->limitText($wpClient['email'] ?? null, 255),
            'assigned_to' => null,
        ];

        $payload['assigned_to'] = $this->leadAssignmentService->assignOwnerId(
            (int) $platform->id,
            $payload,
            null
        );

        return $payload;
    }

    private function normalizePhone(string $phone, string $prefix = '254'): ?string
    {
        $normalized = preg_replace('/[^\d+]/', '', $phone);
        $normalized = ltrim((string) $normalized, '+');

        if (str_starts_with($normalized, '0')) {
            $normalized = $prefix . substr($normalized, 1);
        }

        return $normalized !== '' ? $normalized : null;
    }

    private function limitText($value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, $limit);
    }

    private function hasAnyDifference(array $before, array $after): bool
    {
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                return true;
            }
        }

        return false;
    }
}

