<?php

namespace App\Services\Messaging;

use App\Models\Client;
use App\Models\Lead;
use App\Support\PhoneNormalizer;
use InvalidArgumentException;

final class MessageRecipient
{
    public function __construct(
        public readonly string $phoneE164,
        public readonly int $platformId,
        public readonly ?int $clientId = null,
        public readonly ?int $leadId = null,
        public readonly ?int $dealId = null,
        public readonly ?int $paymentId = null,
        public readonly ?string $locale = null,
    ) {
        if ($this->phoneE164 === '') {
            throw new InvalidArgumentException('Message recipient requires a normalized phone number.');
        }

        if ($this->platformId <= 0) {
            throw new InvalidArgumentException('Message recipient requires a platform id.');
        }
    }

    public static function fromClient(Client $client): self
    {
        $phone = PhoneNormalizer::normalize($client->phone_normalized, (string) optional($client->platform)->phone_prefix ?: '254');

        if (!$phone) {
            throw new InvalidArgumentException('Client does not have a valid phone number.');
        }

        return new self(
            phoneE164: $phone,
            platformId: (int) $client->platform_id,
            clientId: (int) $client->id,
        );
    }

    public static function fromLead(Lead $lead): self
    {
        $phone = PhoneNormalizer::normalize($lead->phone_normalized, (string) optional($lead->platform)->phone_prefix ?: '254');

        if (!$phone) {
            throw new InvalidArgumentException('Lead does not have a valid phone number.');
        }

        return new self(
            phoneE164: $phone,
            platformId: (int) $lead->platform_id,
            leadId: (int) $lead->id,
        );
    }

    public static function fromPhone(string $phone, int $platformId, string $prefix = '254'): self
    {
        $normalized = PhoneNormalizer::normalize($phone, $prefix);

        if (!$normalized) {
            throw new InvalidArgumentException('A valid phone number is required.');
        }

        return new self(
            phoneE164: $normalized,
            platformId: $platformId,
        );
    }

    public function withPaymentId(?int $paymentId): self
    {
        return new self(
            phoneE164: $this->phoneE164,
            platformId: $this->platformId,
            clientId: $this->clientId,
            leadId: $this->leadId,
            dealId: $this->dealId,
            paymentId: $paymentId,
            locale: $this->locale,
        );
    }
}
