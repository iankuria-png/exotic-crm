<?php

namespace App\Services\Messaging;

use App\Models\MessagingSuppression;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

class SuppressionService
{
    private const CHANNELS = ['whatsapp', 'sms', 'email', 'all'];
    private const REASONS = ['keyword_stop', 'manual', 'complaint', 'bounce', 'imported'];

    public function isSuppressed(?string $phone, string $channel, ?int $platformId = null): bool
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $this->assertChannel($channel);

        if (!$normalizedPhone) {
            return false;
        }

        return $this->activeScope($normalizedPhone, $channel, $platformId)->exists();
    }

    public function recordOptOut(
        ?string $phone,
        string $channel,
        string $reason,
        ?int $sourceMessageId = null,
        ?int $platformId = null,
        ?string $email = null,
        ?string $notes = null
    ): MessagingSuppression {
        $normalizedPhone = $this->normalizePhone($phone);
        $this->assertChannel($channel);
        $this->assertReason($reason);

        if (!$normalizedPhone) {
            throw new InvalidArgumentException('A valid phone number is required to record a messaging suppression.');
        }

        $existing = $this->activeScope($normalizedPhone, $channel, $platformId)
            ->where('channel', $channel)
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return MessagingSuppression::create([
                'platform_id' => $platformId,
                'phone_e164' => $normalizedPhone,
                'email' => $email,
                'channel' => $channel,
                'reason' => $reason,
                'source_message_id' => $sourceMessageId,
                'opted_out_at' => now(),
                'notes' => $notes,
            ]);
        } catch (QueryException $exception) {
            $existing = MessagingSuppression::query()
                ->active()
                ->where('platform_id', $platformId)
                ->where('phone_e164', $normalizedPhone)
                ->where('channel', $channel)
                ->first();

            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    public function revoke(int|MessagingSuppression $suppression, int|User|null $actor = null): MessagingSuppression
    {
        $model = $suppression instanceof MessagingSuppression
            ? $suppression
            : MessagingSuppression::findOrFail($suppression);

        if ($model->revoked_at) {
            return $model;
        }

        $model->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $actor instanceof User ? $actor->id : $actor,
        ])->save();

        return $model->refresh();
    }

    private function activeScope(string $normalizedPhone, string $channel, ?int $platformId)
    {
        return MessagingSuppression::query()
            ->active()
            ->where('phone_e164', $normalizedPhone)
            ->whereIn('channel', $channel === 'all' ? ['all'] : [$channel, 'all'])
            ->where(function ($query) use ($platformId) {
                if ($platformId === null) {
                    $query->whereNull('platform_id');

                    return;
                }

                $query->whereNull('platform_id')
                    ->orWhere('platform_id', $platformId);
            });
    }

    private function normalizePhone(?string $phone): ?string
    {
        return PhoneNormalizer::normalize($phone);
    }

    private function assertChannel(string $channel): void
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException("Unsupported messaging suppression channel [{$channel}].");
        }
    }

    private function assertReason(string $reason): void
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException("Unsupported messaging suppression reason [{$reason}].");
        }
    }
}
