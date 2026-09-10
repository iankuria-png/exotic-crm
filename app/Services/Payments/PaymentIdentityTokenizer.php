<?php

namespace App\Services\Payments;

use App\Models\Client;
use App\Models\Payment;

class PaymentIdentityTokenizer
{
    public function legacyTokens(Payment $payment): array
    {
        $tokens = [];
        $phone = $this->legacyPhone($payment->phone);

        if ($phone !== null) {
            $tokens[] = 'phone:'.$phone;
        }

        if ($payment->client_id) {
            $tokens[] = 'client:'.(int) $payment->client_id;
        }

        if (empty($tokens)) {
            $tokens[] = 'payment:'.(int) $payment->id;
        }

        return $tokens;
    }

    public function bridgeTokens(Payment $payment, ?string $phonePrefix = null): array
    {
        $tokens = $this->legacyTokens($payment);
        $canonical = $this->canonicalPhone($payment->phone, $phonePrefix ?? '254');

        if ($canonical !== null) {
            $tokens[] = 'phone:'.$canonical;
        }

        return array_values(array_unique($tokens));
    }

    public function clientTokens(Client $client, ?string $phonePrefix = null): array
    {
        $tokens = ['client:'.(int) $client->id];
        $canonical = $this->canonicalPhone($client->phone_normalized, $phonePrefix ?? (string) ($client->platform?->phone_prefix ?: '254'));

        if ($canonical !== null) {
            $tokens[] = 'phone:'.$canonical;
        }

        return array_values(array_unique($tokens));
    }

    public function legacyPhone(?string $phone): ?string
    {
        $value = preg_replace('/\D/', '', (string) $phone);

        if (! is_string($value)) {
            return null;
        }

        $value = ltrim($value, '0');

        return $value !== '' ? $value : null;
    }

    public function canonicalPhone(?string $phone, string $prefix = '254'): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $value = preg_replace('/[^\d+]/', '', $phone);

        if (! is_string($value)) {
            return null;
        }

        $value = ltrim($value, '+');

        if (str_starts_with($value, '0')) {
            $value = $prefix.substr($value, 1);
        }

        return $value !== '' ? $value : null;
    }
}
