<?php

namespace App\Jobs;

use App\Models\Platform;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SendMarketDownAlertRecipientJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    /**
     * @param  array{id:int,name:string,role:string,phone:?string}  $recipient
     */
    public function __construct(
        public readonly int $platformId,
        public readonly string $eventKey,
        public readonly array $recipient,
        public readonly string $failureClass,
        public readonly string $errorMessage,
    ) {
        $this->onQueue('alerts');
    }

    public function uniqueId(): string
    {
        return sprintf(
            'market-down-alert-recipient:%s:%d',
            $this->eventKey,
            (int) ($this->recipient['id'] ?? 0)
        );
    }

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function handle(NotificationService $notificationService): void
    {
        $platform = Platform::query()
            ->select(['id', 'name', 'country', 'phone_prefix', 'health_status', 'health_error', 'health_checked_at'])
            ->find($this->platformId);

        if (! $platform) {
            return;
        }

        $phone = trim((string) ($this->recipient['phone'] ?? ''));
        if ($phone === '') {
            Log::info('SendMarketDownAlertRecipientJob: skipping recipient without phone.', [
                'platform_id' => (int) $platform->id,
                'event_key' => $this->eventKey,
                'recipient_id' => (int) ($this->recipient['id'] ?? 0),
            ]);

            return;
        }

        $result = $notificationService->sendSms($phone, $this->buildMessage($platform), [
            'platform_id' => (int) $platform->id,
            'phone_prefix' => $platform->phone_prefix ?: null,
            'alert_type' => 'market_down',
        ]);

        if (! ($result['success'] ?? false)) {
            Log::warning('Market-down SMS dispatch failed.', [
                'platform_id' => (int) $platform->id,
                'event_key' => $this->eventKey,
                'recipient_id' => (int) ($this->recipient['id'] ?? 0),
                'provider_result' => $result,
            ]);

            throw new RuntimeException((string) ($result['message'] ?? $result['provider_response'] ?? 'Market-down SMS dispatch failed.'));
        }

        Log::info('Market-down SMS sent.', [
            'platform_id' => (int) $platform->id,
            'event_key' => $this->eventKey,
            'recipient_id' => (int) ($this->recipient['id'] ?? 0),
            'provider' => $result['provider'] ?? null,
            'status' => $result['status'] ?? null,
        ]);
    }

    private function buildMessage(Platform $platform): string
    {
        $name = mb_substr((string) ($platform->name ?: 'Unknown market'), 0, 60);
        $failure = mb_substr(str_replace('_', ' ', $this->failureClass), 0, 40);
        $error = mb_substr((string) ($this->errorMessage ?: $platform->health_error ?: 'No error message captured.'), 0, 120);
        $time = now()->format('M j H:i');

        return "Market alert: {$name} is DOWN ({$failure}). {$error}. {$time}. Check CRM.";
    }
}
