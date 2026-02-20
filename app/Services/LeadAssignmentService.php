<?php

namespace App\Services;

use App\Models\User;

class LeadAssignmentService
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService
    ) {
    }

    public function assignOwnerId(int $platformId, array $leadData = [], ?int $existingOwnerId = null): ?int
    {
        if ($existingOwnerId) {
            $existingOwner = User::find($existingOwnerId);

            if (
                $existingOwner &&
                $existingOwner->status === 'active' &&
                $this->marketAuthorizationService->userCanAccessPlatform($existingOwner, $platformId)
            ) {
                return (int) $existingOwner->id;
            }
        }

        $candidates = $this->marketAuthorizationService->eligibleOwnersForPlatform($platformId);
        if ($candidates->isEmpty()) {
            return null;
        }

        $seed = $this->assignmentSeed($leadData);
        $index = abs(crc32($seed)) % $candidates->count();

        return (int) $candidates->values()->get($index)->id;
    }

    private function assignmentSeed(array $leadData): string
    {
        $parts = [
            $leadData['wp_post_id'] ?? '',
            $leadData['wp_user_id'] ?? '',
            $leadData['phone_normalized'] ?? '',
            $leadData['email'] ?? '',
            $leadData['name'] ?? '',
        ];

        $seed = implode('|', array_map(fn ($part) => (string) $part, $parts));

        return trim($seed) !== '' ? $seed : uniqid('lead', true);
    }
}

