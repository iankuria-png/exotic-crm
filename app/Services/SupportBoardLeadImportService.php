<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Lead;
use App\Models\Platform;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Log;

class SupportBoardLeadImportService
{
    private ?string $lastProcessedName = null;

    /**
     * Process a single SB user candidate for lead import.
     * Called by RunSbLeadImportJob for each user in the candidate list.
     *
     * @return string One of: created, updated, skipped_existing_client, skipped_existing_lead
     */
    public function processCandidate(
        SupportBoardService $sbService,
        Platform $platform,
        int $platformId,
        string $phonePrefix,
        int $sbUserId
    ): string {
        $this->lastProcessedName = null;

        // Check clients by sb_user_id
        $existingClientBySb = Client::query()
            ->where('platform_id', $platformId)
            ->where('sb_user_id', $sbUserId)
            ->exists();
        if ($existingClientBySb) {
            return 'skipped_existing_client';
        }

        // Fetch SB user details
        $sbUser = $sbService->getUser($sbUserId, true);
        if (!$sbUser) {
            return 'skipped_existing_client'; // Can't process without user data
        }

        $name = trim(($sbUser['first_name'] ?? '') . ' ' . ($sbUser['last_name'] ?? ''));
        $this->lastProcessedName = $name !== '' ? $name : "SB User #{$sbUserId}";
        $email = strtolower(trim((string) ($sbUser['email'] ?? '')));
        $phone = $this->extractPhone($sbUser, $phonePrefix);

        // Check clients by phone or email
        if ($phone) {
            $clientByPhone = Client::query()
                ->where('platform_id', $platformId)
                ->where('phone_normalized', $phone)
                ->exists();
            if ($clientByPhone) {
                return 'skipped_existing_client';
            }
        }
        if ($email !== '') {
            $clientByEmail = Client::query()
                ->where('platform_id', $platformId)
                ->where('email', $email)
                ->exists();
            if ($clientByEmail) {
                return 'skipped_existing_client';
            }
        }

        // Check leads by sb_user_id
        $existingLeadBySb = Lead::query()
            ->where('platform_id', $platformId)
            ->where('sb_user_id', $sbUserId)
            ->first();
        if ($existingLeadBySb) {
            $this->refreshLeadTraceability($existingLeadBySb, $sbUser, $sbUserId);
            return 'updated';
        }

        // Check leads by phone or email
        if ($phone) {
            $leadByPhone = Lead::query()
                ->where('platform_id', $platformId)
                ->where('phone_normalized', $phone)
                ->first();
            if ($leadByPhone) {
                $this->refreshLeadTraceability($leadByPhone, $sbUser, $sbUserId);
                return 'updated';
            }
        }
        if ($email !== '') {
            $leadByEmail = Lead::query()
                ->where('platform_id', $platformId)
                ->where('email', $email)
                ->first();
            if ($leadByEmail) {
                $this->refreshLeadTraceability($leadByEmail, $sbUser, $sbUserId);
                return 'updated';
            }
        }

        // Create new lead
        $sourceUrl = $this->extractSourceUrl($sbUser);

        Lead::create([
            'platform_id' => $platformId,
            'name' => $name !== '' ? $name : null,
            'phone_normalized' => $phone,
            'email' => $email !== '' ? $email : null,
            'source' => 'support_chat',
            'source_url' => $sourceUrl,
            'status' => 'new',
            'sb_user_id' => $sbUserId,
            'sb_user_type' => $sbUser['user_type'] ?? null,
            'sb_last_activity_at' => $sbUser['last_activity'] ?? null,
            'sb_metadata_snapshot' => [
                'first_name' => $sbUser['first_name'] ?? null,
                'last_name' => $sbUser['last_name'] ?? null,
                'profile_image' => $sbUser['profile_image'] ?? null,
                'creation_time' => $sbUser['creation_time'] ?? null,
            ],
        ]);

        return 'created';
    }

    /**
     * Fetch all conversation user IDs for a platform (used at run start to build candidate list).
     *
     * @return array<int> Unique SB user IDs
     */
    public function fetchCandidateUserIds(Platform $platform, string $mode = 'bootstrap'): array
    {
        $sbService = new SupportBoardService($platform);
        if (!$sbService->isConfigured()) {
            throw new \RuntimeException('Support Board is not configured for this market.');
        }

        $conversations = $mode === 'incremental'
            ? $sbService->getNewConversations()
            : $this->fetchAllConversationsPaginated($sbService);

        return collect($conversations)
            ->map(fn (array $c) => (int) ($c['user_id'] ?? 0))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function getLastProcessedName(): ?string
    {
        return $this->lastProcessedName;
    }

    private function fetchAllConversationsPaginated(SupportBoardService $sbService): array
    {
        $all = [];
        $page = 1;
        $maxPages = 50;

        while ($page <= $maxPages) {
            $batch = $sbService->getAllConversations($page);
            if (empty($batch)) {
                break;
            }
            $all = array_merge($all, $batch);
            $page++;
        }

        return $all;
    }

    private function refreshLeadTraceability(Lead $lead, array $sbUser, int $sbUserId): void
    {
        $updates = [];

        if (!$lead->sb_user_id) {
            $updates['sb_user_id'] = $sbUserId;
        }
        if (!$lead->sb_user_type && !empty($sbUser['user_type'])) {
            $updates['sb_user_type'] = $sbUser['user_type'];
        }
        if (!$lead->sb_last_activity_at && !empty($sbUser['last_activity'])) {
            $updates['sb_last_activity_at'] = $sbUser['last_activity'];
        }

        if (!empty($updates)) {
            $lead->update($updates);
        }
    }

    private function extractPhone(array $sbUser, string $phonePrefix): ?string
    {
        $rawPhone = null;

        // Check details/extras for phone
        if (!empty($sbUser['details']) && is_array($sbUser['details'])) {
            foreach ($sbUser['details'] as $detail) {
                $slug = strtolower(trim((string) ($detail['slug'] ?? '')));
                if (in_array($slug, ['phone', 'phone-number', 'phone_number', 'whatsapp'], true)) {
                    $rawPhone = trim((string) ($detail['value'] ?? ''));
                    if ($rawPhone !== '') {
                        break;
                    }
                }
            }
        }

        if (!$rawPhone) {
            return null;
        }

        return PhoneNormalizer::normalize($rawPhone, $phonePrefix);
    }

    private function extractSourceUrl(array $sbUser): ?string
    {
        if (!empty($sbUser['details']) && is_array($sbUser['details'])) {
            foreach ($sbUser['details'] as $detail) {
                $slug = strtolower(trim((string) ($detail['slug'] ?? '')));
                if (in_array($slug, ['current_url', 'landing_url', 'current-url', 'landing-url'], true)) {
                    $value = trim((string) ($detail['value'] ?? ''));
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        return null;
    }
}
