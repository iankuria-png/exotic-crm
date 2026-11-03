<?php

namespace App\Jobs;

use App\Models\TourOrderStop;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTourFailureNotice implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 1;

    public function __construct(public int $stopId)
    {
        $this->onQueue('alerts');
    }

    public function uniqueId(): string
    {
        return 'tour-refund:'.$this->stopId;
    }

    public function handle(NotificationService $notifications): void
    {
        $stop = TourOrderStop::with('order.client')->find($this->stopId);
        if (! $stop || ! $stop->refunded_at || $stop->order->environment !== 'production' || app()->environment('local', 'testing')) {
            return;
        }
        $order = $stop->order;
        $amount = number_format($stop->allocation_minor / \App\Services\Tours\Money::factor($order->currency), 2);
        $result = $notifications->sendSmsToClient($order->client, 'A tour destination could not go live. '.$order->currency.' '.$amount.' has been returned to your wallet. Open Tours to check your route.', ['notification_purpose' => 'tour_failure_refund', 'source' => 'automated', 'dedupe_key' => 'tour-refund:'.$stop->id]);
        $stop->update(['payload' => array_merge($stop->payload, ['refund_notification_status' => $result['status'] ?? 'unknown'])]);
    }
}
