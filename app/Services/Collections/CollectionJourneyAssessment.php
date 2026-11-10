<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionReceipt;

/** Read-only explanation of the worker's matching rules. This never authorizes a write. */
class CollectionJourneyAssessment
{
    public function forReceipt(CollectionReceipt $receipt, array $visibleClientIds): array
    {
        $matching = app(CollectionMatchService::class)->automatic($receipt);
        $selected = $matching['selected_client_id'];
        $profile = $selected && in_array($selected, $visibleClientIds, true)
            ? collect($matching['suggestions'])->firstWhere('id', $selected) : null;
        $result = ['advisory' => true, 'threshold' => $matching['threshold'], 'profile' => $profile,
            'package' => ['state' => 'waiting_for_profile'], 'activation' => ['state' => 'checks_pending', 'reason' => null]];
        if ($profile) {
            try {
                $candidates = app(CollectionDecisionService::class)->candidates($receipt);
                if (count($candidates) > 1) {
                    throw new CollectionException('multiple_purchase_candidates');
                }
                $candidate = $candidates[0] ?? null;
                if ($candidate && (! $candidate['automatic_eligible'] || (int) $candidate['client_id'] !== $selected)) {
                    throw new CollectionException($candidate['reasons'][0] ?? 'reviewed_purchase_authority_required');
                }
                if ($candidate) {
                    $result['package'] = ['state' => 'matched', 'source' => 'reviewed_purchase', 'purchase' => $candidate];
                } else {
                    $client = app(CollectionPurchaseService::class)->selectableClients()->findOrFail($selected);
                    $result['package'] = ['state' => 'matched', 'source' => 'catalogue',
                        'purchase' => app(CollectionCataloguePurchaseMatch::class)->preview($receipt, $client)];
                }
            } catch (CollectionException $e) {
                $result['package'] = ['state' => 'needs_review', 'reason' => $e->reason];
            }
        }
        try {
            app(CollectionGate::class)->assertFunds($receipt);
            if (! data_get($receipt->account->policy, 'automation_enabled', false)) {
                throw new CollectionException('automatic_activation_paused');
            }
            app(CollectionAutomationEpoch::class)->assertReceipt($receipt);
            if (! $profile || $result['package']['state'] !== 'matched') {
                throw new CollectionException('profile_or_package_requires_review');
            }
            $platforms = [$profile['platform_id']];
            if (! app(CollectionAutomationPolicy::class)->independentSubsidiary($receipt->account) && ($secondary = config('collection.subsidiary_platforms.'.$profile['platform_id']))) {
                $platforms[] = (int) $secondary;
            }
            app(CollectionGate::class)->assertWrites($receipt->account, $platforms, true);
            // Live identity, coverage, exposure and reservation checks still belong to the worker.
        } catch (CollectionException $e) {
            $result['activation'] = ['state' => $e->reason === 'automatic_activation_paused' ? 'paused' : 'held', 'reason' => $e->reason];
        }

        return $result;
    }
}
