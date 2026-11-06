<?php

namespace App\Services\Collections;

use App\Support\PhoneNormalizer;

class CollectionPrivacy
{
    public function fingerprint(string $value): string
    {
        $key = config('collection.payer_fingerprint_key');
        if (! is_string($key) || strlen($key) < 32) {
            throw new CollectionException('collection_fingerprint_key_required', 503);
        }

        return hash_hmac('sha256', $value, $key);
    }

    public function tzPhone(?string $phone): ?string
    {
        $normalized = PhoneNormalizer::normalize($phone, '255');

        return preg_match('/^255[67]\d{8}$/D', (string) $normalized) ? $normalized : null;
    }
}
