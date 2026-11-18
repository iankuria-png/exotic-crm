<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionAccount;

/**
 * Reviewed receiving parsers by enrolled provider. An account only ever accepts its own provider's
 * layouts; display services dispatch by sender and still verify reference/currency/amount per receipt.
 */
class CollectionParserRegistry
{
    private const PROVIDERS = [
        'vodacom_mpesa_tz' => ['country' => 'TZ', 'parser' => TzMpesaParser::class, 'currency' => 'TZS', 'filter' => 'exotic-tz-v1'],
        'mtn_momo_gh' => ['country' => 'GH', 'parser' => GhMtnMomoParser::class, 'currency' => 'GHS', 'filter' => 'exotic-gh-v1'],
    ];

    public function forAccount(CollectionAccount $account): TzMpesaParser|GhMtnMomoParser|null
    {
        $provider = self::PROVIDERS[$account->provider_key] ?? null;

        return $provider && $provider['country'] === $account->country ? app($provider['parser']) : null;
    }

    public function forSender(string $sender): TzMpesaParser|GhMtnMomoParser|null
    {
        return match ($sender) {
            'M-PESA' => app(TzMpesaParser::class),
            GhMtnMomoParser::SENDER => app(GhMtnMomoParser::class),
            default => null,
        };
    }

    public function filterVersion(string $country): ?string
    {
        foreach (self::PROVIDERS as $provider) {
            if ($provider['country'] === $country) {
                return $provider['filter'];
            }
        }

        return null;
    }

    public function parse(string $sender, string $body): array
    {
        return $this->forSender($sender)?->parse($sender, $body) ?? ['kind' => 'unsupported', 'reason' => 'sender_not_supported'];
    }

    public function payerDetails(string $sender, string $body): array
    {
        return $this->forSender($sender)?->payerDetails($sender, $body) ?? ['name' => null, 'party' => null, 'reference' => null, 'phone' => null];
    }

    public function balanceMinor(string $sender, string $body): ?int
    {
        return $this->forSender($sender)?->balanceMinor($body);
    }

    /** Receiving-wallet phone normalization for the account's country. */
    public function phone(string $country, ?string $phone): ?string
    {
        return match ($country) {
            'GH' => app(GhMtnMomoParser::class)->phone($phone),
            default => app(CollectionPrivacy::class)->tzPhone($phone),
        };
    }

    /** Staff search accepts either enrolled country's phone format. */
    public function anyPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return str_starts_with($digits, '233') ? $this->phone('GH', $phone) : (app(CollectionPrivacy::class)->tzPhone($phone) ?? $this->phone('GH', $phone));
    }
}
