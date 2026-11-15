<?php

namespace App\Services\FlexibleAmounts;

use App\Models\ClientPayLink;
use Illuminate\Support\Facades\Crypt;

class FlexibleLinkOfferService
{
    public function issue(ClientPayLink $link, ?string $suggestion): string
    {
        if ($suggestion !== null) {
            FlexiblePricingEngine::minor($suggestion);
        }

        return Crypt::encryptString(json_encode(['link_id' => $link->id, 'client_id' => $link->client_id, 'platform_id' => $link->platform_id, 'suggested_amount' => $suggestion, 'expires_at' => now()->addDays(30)->timestamp], JSON_THROW_ON_ERROR));
    }

    public function resolve(ClientPayLink $link, ?string $token): array
    {
        if (! $token) {
            return ['surface' => 'pay_link', 'suggested_amount' => null];
        }
        try {
            $offer = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(422, 'This suggested offer expired. Ask the team for a new link.');
        }
        abort_unless(($offer['link_id'] ?? 0) === $link->id && ($offer['client_id'] ?? 0) === $link->client_id && ($offer['platform_id'] ?? 0) === $link->platform_id && ($offer['expires_at'] ?? 0) > now()->timestamp, 422, 'This suggested offer expired. Ask the team for a new link.');

        return ['surface' => 'crm_link', 'suggested_amount' => $offer['suggested_amount'] ?? null];
    }
}
