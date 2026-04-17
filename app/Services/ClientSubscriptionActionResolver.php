<?php

namespace App\Services;

use App\Models\Client;

class ClientSubscriptionActionResolver
{
    public function resolveNoDealDeactivation(Client $client, array $context = []): array
    {
        $hasLinkedWpProfile = array_key_exists('has_linked_wp_profile', $context)
            ? (bool) $context['has_linked_wp_profile']
            : (int) ($client->wp_post_id ?? 0) > 0;

        $hasActiveDeal = array_key_exists('has_active_deal', $context)
            ? (bool) $context['has_active_deal']
            : $this->hasActiveDeal($client);

        $hasTrackedDealHistory = array_key_exists('has_tracked_deal_history', $context)
            ? (bool) $context['has_tracked_deal_history']
            : $this->hasTrackedDealHistory($client);

        $allowTrackedDealHistory = (bool) ($context['allow_tracked_deal_history'] ?? false);
        $alreadyCanonicalDeactivated = array_key_exists('is_canonical_deactivated', $context)
            ? (bool) $context['is_canonical_deactivated']
            : $this->isCanonicalDeactivated($client);

        $disabledReason = null;

        if ($hasActiveDeal) {
            $disabledReason = 'This client has an active CRM subscription. Deactivate the tracked deal instead.';
        } elseif (!$allowTrackedDealHistory && $hasTrackedDealHistory) {
            $disabledReason = 'This client already has CRM subscription history. Use the tracked subscription record instead.';
        } elseif (!$hasLinkedWpProfile) {
            $disabledReason = 'This client is not linked to a WordPress profile yet.';
        } elseif ($alreadyCanonicalDeactivated) {
            $disabledReason = 'This profile is already fully deactivated in WordPress.';
        }

        $canDeactivate = $disabledReason === null;

        return [
            'can_deactivate_without_deal' => $canDeactivate,
            'deactivation_scope' => $canDeactivate ? 'client' : null,
            'deactivation_label' => 'Deactivate',
            'deactivation_disabled_reason' => $disabledReason,
        ];
    }

    private function hasActiveDeal(Client $client): bool
    {
        if ($client->relationLoaded('activeDeal')) {
            return $client->activeDeal !== null;
        }

        return $client->activeDeal()->exists();
    }

    private function hasTrackedDealHistory(Client $client): bool
    {
        if (array_key_exists('deal_id', $client->getAttributes())) {
            return !empty($client->getAttribute('deal_id'));
        }

        if ($client->relationLoaded('deals')) {
            return $client->deals->isNotEmpty();
        }

        return false;
    }

    private function isCanonicalDeactivated(Client $client): bool
    {
        return (string) ($client->profile_status ?? '') === 'private'
            && (bool) $client->needs_payment
            && !(bool) $client->notactive
            && !(bool) $client->premium
            && !(bool) $client->featured
            && empty($client->escort_expire)
            && empty($client->premium_expire)
            && empty($client->featured_expire);
    }
}
