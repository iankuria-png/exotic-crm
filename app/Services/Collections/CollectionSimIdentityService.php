<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionDevice;

class CollectionSimIdentityService
{
    public function mode(CollectionDevice $device): string
    {
        $mode = data_get($device->acceptance, 'sim_identity_mode', 'iccid_v1');

        return is_string($mode) ? $mode : 'unsupported';
    }

    public function recipient(CollectionDevice $device, mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        if ($this->mode($device) === 'android_subscription_v1') {
            return app(CollectionPrivacy::class)->tzPhone($value);
        }

        return preg_replace('/\D/', '', $value) ?: null;
    }

    public function inventoryMatches(CollectionDevice $device, object $binding, array $card): bool
    {
        if ((int) ($card['simNumber'] ?? -1) !== (int) $binding->sim_number
            || ($card['carrierName'] ?? null) !== $binding->carrier_name) {
            return false;
        }
        $phone = $this->recipient($device, $card['phoneNumber'] ?? null);
        if (! empty($card['phoneNumber']) && (! $phone || ! $this->recipientMatches($binding, $phone))) {
            return false;
        }
        if ($this->mode($device) === 'android_subscription_v1') {
            return $this->subscriptionMatches($device, $binding, $card['subscriptionId'] ?? null);
        }
        if ($this->mode($device) !== 'iccid_v1') {
            return false;
        }
        $iccid = $card['iccid'] ?? null;

        return $phone && $this->recipientMatches($binding, $phone) && is_string($iccid) && $iccid !== '' && $binding->iccid_fingerprint
            && hash_equals($binding->iccid_fingerprint, app(CollectionPrivacy::class)->fingerprint($iccid));
    }

    public function messageMatches(CollectionDevice $device, object $binding, array $payload): bool
    {
        $phone = $this->recipient($device, $payload['recipient'] ?? null);
        if (! $phone || ! $this->recipientMatches($binding, $phone)) {
            return false;
        }
        if ($this->mode($device) === 'iccid_v1') {
            return true; // Existing mode validates the ICCID in startup inventory.
        }
        if ($this->mode($device) !== 'android_subscription_v1'
            || ! $this->subscriptionMatches($device, $binding, $payload['subscriptionId'] ?? null)
            || ($payload['carrierName'] ?? null) !== $binding->carrier_name) {
            return false;
        }
        $source = $payload['recipientSource'] ?? null;
        $measured = $payload['measuredRecipient'] ?? null;
        if ($source === 'enrollment') {
            return $measured === null; // Explicitly reviewed mapping; never represented as measured.
        }

        return $source === 'device' && is_string($measured)
            && $this->recipient($device, $measured) === $phone;
    }

    private function subscriptionMatches(CollectionDevice $device, object $binding, mixed $actual): bool
    {
        $expected = data_get($device->acceptance, 'android_subscription_ids.'.(int) $binding->sim_number);

        return is_int($expected) && $expected > 0 && is_int($actual) && $actual === $expected;
    }

    private function recipientMatches(object $binding, string $phone): bool
    {
        return hash_equals($binding->recipient_fingerprint, app(CollectionPrivacy::class)->fingerprint($phone));
    }
}
