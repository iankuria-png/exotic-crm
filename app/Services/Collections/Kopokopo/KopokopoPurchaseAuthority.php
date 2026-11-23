<?php

namespace App\Services\Collections\Kopokopo;

use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Collections\CollectionReceipt;
use App\Services\Collections\CollectionException;

class KopokopoPurchaseAuthority
{
    public function evidence(CollectionReceipt $receipt, array $valid, array $result): array
    {
        if ($receipt->account->provider_key !== KopokopoFeedService::PROVIDER) {
            return $result;
        }
        $authorities = array_filter($valid, fn ($i) => in_array($i->authority, ['reviewed_quote', 'standing_agreement'], true)
            && ($i->authority === 'standing_agreement' || $i->provider_reference === $receipt->reference_norm));
        foreach ($result['suggestions'] as &$row) {
            $row['reasons'] = array_map(fn ($s) => str_replace(['SMS payer', 'SMS', 'advert phone'], ['KopoKopo payer', 'provider receipt', 'profile phone'], $s), $row['reasons']);
            if (! $authorities) {
                $row['score'] = min(79, $row['score']);
                $row['reasons'][] = 'Payer history is a suggestion. Review this purchase or use an approved standing agreement.';
            }
        }
        unset($row);
        if (! $authorities) {
            $result['recommended_client_id'] = null;
            $result['guards'] = array_values(array_unique([...($result['guards'] ?? []), 'current_purchase_authority_required']));
        }

        return $result;
    }

    public function assertAutomatic(CollectionPurchaseIntent $intent, CollectionReceipt $receipt): void
    {
        if ($receipt->account->provider_key !== KopokopoFeedService::PROVIDER) {
            return;
        }
        if (! in_array($intent->authority, ['reviewed_quote', 'standing_agreement'], true)
            || ($intent->authority === 'reviewed_quote' && $intent->provider_reference !== $receipt->reference_norm)) {
            throw new CollectionException('current_purchase_authority_required');
        }
    }
}
