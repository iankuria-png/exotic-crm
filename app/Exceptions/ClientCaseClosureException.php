<?php

namespace App\Exceptions;

use App\Models\Client;
use App\Models\Deal;
use RuntimeException;

class ClientCaseClosureException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $httpStatus = 422,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function context(): array
    {
        return $this->context;
    }

    public static function invalidReason(string $code): self
    {
        return new self(
            "Unknown close reason: {$code}.",
            'invalid_reason',
            422,
            ['reason_code' => $code],
        );
    }

    public static function noteRequired(string $code): self
    {
        return new self(
            'A note is required when the reason is "Other".',
            'note_required',
            422,
            ['reason_code' => $code],
        );
    }

    public static function alreadyClosed(Client $client): self
    {
        return new self(
            'This case is already closed.',
            'already_closed',
            409,
            [
                'client_id' => (int) $client->id,
                'closed_at' => optional($client->closed_at)?->toDateTimeString(),
                'close_reason_code' => $client->close_reason_code,
            ],
        );
    }

    public static function activeSubscription(Client $client, Deal $deal): self
    {
        return new self(
            'Cannot close a case with an active subscription. Deactivate the subscription first.',
            'active_subscription',
            422,
            [
                'client_id' => (int) $client->id,
                'deal_id' => (int) $deal->id,
            ],
        );
    }

    public static function notClosed(Client $client): self
    {
        return new self(
            'This case is not closed, nothing to reopen.',
            'not_closed',
            422,
            ['client_id' => (int) $client->id],
        );
    }

    public static function pastPurgeWindow(Client $client): self
    {
        return new self(
            'This case is past the 30-day recovery window and will be purged shortly.',
            'past_purge_window',
            422,
            [
                'client_id' => (int) $client->id,
                'purge_after' => optional($client->purge_after)?->toDateTimeString(),
            ],
        );
    }
}
