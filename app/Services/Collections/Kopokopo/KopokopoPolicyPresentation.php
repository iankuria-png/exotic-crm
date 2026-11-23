<?php

namespace App\Services\Collections\Kopokopo;

use App\Models\Collections\CollectionAccount;

class KopokopoPolicyPresentation
{
    public function readiness(CollectionAccount $account, array $checks): array
    {
        if ($account->provider_key !== KopokopoFeedService::PROVIDER) {
            return $checks;
        }
        $labels = ['account' => 'Receiving till and company ownership verified', 'device' => 'Provider reconciliation is current',
            'parser' => 'Current KopoKopo payload version accepted', 'authority' => 'Reviewed purchase or standing agreement available',
            'held_out_parser_accepted_at' => 'Provider receipt fields and sender identity accepted',
            'device_capture_accepted_at' => 'Signed delivery and recovery accepted'];
        foreach ($checks as &$check) {
            $key = $check['key'];
            if (isset($labels[$key])) {
                $check['label'] = $labels[$key];
            }
            if ($key === 'device_capture_accepted_at') {
                $check['title'] = 'Signed delivery and independent reconciliation';
                $check['detail'] = 'A genuine incoming receipt and reversal handling were checked. Recovery polling catches missed deliveries after a worker restart, including a quiet till.';
            } elseif ($key === 'held_out_parser_accepted_at') {
                $check['title'] = 'KopoKopo receipt interpretation';
                $check['detail'] = 'Live raw-body signatures, till/currency/status, decimal amounts, timestamps and sender identifier consistency with the statement are accepted.';
            } elseif ($key === 'device') {
                $check['action'] = 'Check the KopoKopo connection and reconciliation status above.';
            } elseif ($key === 'account') {
                $check['action'] = 'Verify ownership of receiving till 268491.';
            } elseif ($key === 'authority') {
                $check['action'] = 'Create a reviewed purchase or manager-approved standing agreement. Payer history alone cannot authorize activation.';
            }
        }

        return $checks;
    }
}
