<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\PremiumContentEvent;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendMonetizationNotice implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 1;

    public function __construct(public int $eventId)
    {
        $this->onQueue('alerts');
    }

    public function uniqueId(): string
    {
        return 'monetize-notice:'.$this->eventId;
    }

    public function handle(NotificationService $notifications): void
    {
        $event = PremiumContentEvent::find($this->eventId);
        if (! $event || data_get($event->metadata_json, 'is_sandbox') || app()->environment('local', 'testing')) {
            return;
        }
        $client = Client::find($event->client_id);
        if (! $client) {
            return;
        }
        $result = $notifications->sendSmsToClient($client, $event->reason, ['notification_purpose' => 'monetize_'.$event->kind, 'source' => 'automated', 'dedupe_key' => 'monetize:'.$event->id]);
        $event->update(['metadata_json' => array_merge($event->metadata_json ?? [], ['notification_status' => $result['status'] ?? 'unknown'])]);
    }
}
