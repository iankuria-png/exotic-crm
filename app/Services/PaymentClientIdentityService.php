<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Payment;
use App\Support\PhoneNormalizer;

/** Read-only customer identity resolution shared by completion, preview and matching. */
class PaymentClientIdentityService
{
    public function resolve(Payment $payment, ?string $prefix = null): array
    {
        $miss = fn (string $reason, string $confidence = 'unmatched') => ['matched' => false, 'confidence' => $confidence, 'reason' => $reason, 'client' => null];
        if ($payment->isVisitorPayment()) {
            return $miss('Visitor payments cannot match advertisers.', 'excluded');
        }
        if (! $payment->platform_id) {
            return $miss('Payment market is missing');
        }
        $base = fn () => Client::query()->where('platform_id', $payment->platform_id);
        if ($payment->client_id) {
            $client = $base()->find($payment->client_id);

            return $client ? ['matched' => true, 'confidence' => 'already_matched', 'client' => $client, 'source' => 'existing'] : $miss('Linked client does not belong to payment market');
        }

        $identities = [];
        foreach (['wp_user_id' => $payment->user_id, 'wp_post_id' => $payment->escort_post_id] as $column => $value) {
            if ((int) $value <= 0) {
                continue;
            }
            $clients = $base()->where($column, $value)->limit(2)->get();
            if ($clients->isEmpty()) {
                return $miss('WordPress identity is not yet in the CRM cache');
            }
            if ($clients->count() !== 1) {
                return $miss('Multiple clients share the WordPress identity', 'auto_low');
            }
            $identities[] = $clients->first();
        }
        if ($identities !== []) {
            if (count(array_unique(array_map(fn (Client $c) => $c->id, $identities))) !== 1) {
                return $miss('WordPress user and profile identities conflict', 'auto_low');
            }

            return ['matched' => true, 'confidence' => 'identity', 'client' => $identities[0], 'source' => 'wordpress_identity'];
        }

        $payment->loadMissing('platform');
        $phone = PhoneNormalizer::normalize($payment->phone, $prefix ?? (string) ($payment->platform?->phone_prefix ?: '254'));
        if (! $phone) {
            return $miss('No phone number');
        }
        $clients = $base()->where('phone_normalized', $phone)->limit(2)->get();
        if ($clients->isEmpty()) {
            return $miss('No client with this phone');
        }
        if ($clients->count() !== 1) {
            return $miss('Multiple clients share this phone number', 'auto_low');
        }

        return ['matched' => true, 'confidence' => 'phone', 'client' => $clients->first(), 'source' => 'phone'];
    }
}
