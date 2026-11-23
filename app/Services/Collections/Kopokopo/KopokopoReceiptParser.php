<?php

namespace App\Services\Collections\Kopokopo;

use App\Services\Collections\CollectionException;
use Illuminate\Support\Carbon;

class KopokopoReceiptParser
{
    public const RECEIVED = 'buygoods_transaction_received';

    public const REVERSED = 'buygoods_transaction_reversed';

    public function decode(string $body): array
    {
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new CollectionException('provider_invalid_json', 422);
        }
        if (! is_array($data) || array_is_list($data)) {
            throw new CollectionException('provider_object_required', 422);
        }

        return $data;
    }

    public function webhook(array $data): array
    {
        $id = $this->identifier($data['id'] ?? null);
        $topic = $data['topic'] ?? null;
        if (! in_array($topic, [self::RECEIVED, self::REVERSED], true) || ($data['event']['type'] ?? null) !== 'Buygoods Transaction') {
            throw new CollectionException('provider_event_type_requires_review', 422);
        }

        return ['event_key' => 'webhook:'.$id, 'topic' => $topic, 'observation' => $this->resource($data['event']['resource'] ?? null, $topic)];
    }

    public function resource(mixed $r, string $topic, bool $statement = false): array
    {
        if (! is_array($r) || ($r['till_number'] ?? null) !== config('collection_kopokopo.till') || ($r['currency'] ?? null) !== 'KES') {
            throw new CollectionException('provider_till_or_currency_mismatch', 422);
        }
        $reference = $this->reference($r['reference'] ?? null);
        $resource = $statement ? null : $this->identifier($r['id'] ?? null);
        if (! is_string($r['status'] ?? null)) {
            throw new CollectionException('provider_receipt_status_invalid', 422);
        }
        $status = strtolower($r['status']);
        $allowed = $topic === self::REVERSED ? ['received', 'reversed', 'complete', 'completed'] : ['received', 'complete', 'completed'];
        if (! in_array($status, $allowed, true)) {
            throw new CollectionException('provider_receipt_not_confirmed', 422);
        }
        $at = $this->timestamp($r['origination_time'] ?? null);
        if ($at->gt(now()->addMinutes(2)) || $at->year < 2000) {
            throw new CollectionException('provider_time_outside_window', 422);
        }
        $hash = $r['hashed_sender_phone'] ?? null;
        if ($hash !== null && $hash !== '' && (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/Di', $hash))) {
            throw new CollectionException('provider_sender_hash_invalid', 422);
        }
        $phoneValue = $r['sender_phone_number'] ?? null;
        if ($phoneValue !== null && ! is_string($phoneValue)) {
            throw new CollectionException('provider_sender_phone_invalid', 422);
        }
        $name = [];
        foreach (['sender_first_name', 'sender_middle_name', 'sender_last_name'] as $field) {
            if (isset($r[$field]) && ! is_string($r[$field])) {
                throw new CollectionException('provider_sender_name_invalid', 422);
            }
            if (! empty($r[$field])) {
                $name[] = trim(preg_replace('/[\x00-\x1f\x7f]/u', '', $r[$field]));
            }
        }

        return ['resource_id' => $resource, 'reference' => $reference, 'principal_minor' => $this->minor($r['amount'] ?? null), 'currency' => 'KES',
            'provider_at' => $at->toIso8601String(), 'payer_phone' => $this->phone($phoneValue),
            'sender_hash' => $hash ? strtolower($hash) : null, 'payer_name' => mb_substr(implode(' ', $name), 0, 120) ?: null,
            'reversed' => $topic === self::REVERSED];
    }

    public function minor(mixed $amount): int
    {
        if ((! is_string($amount) && ! is_int($amount) && ! is_float($amount)) || ! preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/D', (string) $amount, $m)) {
            throw new CollectionException('provider_amount_invalid', 422);
        }
        $minor = ((int) $m[1] * 100) + (int) str_pad($m[2] ?? '', 2, '0');
        if ($minor <= 0 || $minor > 90000000000000) {
            throw new CollectionException('provider_amount_invalid', 422);
        }

        return $minor;
    }

    public function phone(?string $value): ?string
    {
        if ($value !== null && ! preg_match('/^[+0-9 ()-]*$/D', $value)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', (string) $value);
        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '254'.substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $digits = '254'.$digits;
        }

        return preg_match('/^254[17]\d{8}$/D', $digits) ? $digits : null;
    }

    public function reference(mixed $value): string
    {
        if (! is_string($value)) {
            throw new CollectionException('provider_reference_invalid', 422);
        }
        $value = strtoupper(preg_replace('/\s+/', '', trim($value)));
        if (! preg_match('/^[A-Z0-9]{8,20}$/D', $value)) {
            throw new CollectionException('provider_reference_invalid', 422);
        }

        return $value;
    }

    public function identifier(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{1,127}$/D', $value)) {
            throw new CollectionException('provider_identifier_invalid', 422);
        }

        return $value;
    }

    public function timestamp(mixed $value): Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            throw new CollectionException('provider_timestamp_offset_required', 422);
        }
        $parts = date_parse($value);
        if ($parts['warning_count'] || $parts['error_count']) {
            throw new CollectionException('provider_timestamp_invalid', 422);
        }
        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            throw new CollectionException('provider_timestamp_invalid', 422);
        }
    }
}
