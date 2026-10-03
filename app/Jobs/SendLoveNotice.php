<?php

namespace App\Jobs;

use App\Models\LoveGift;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendLoveNotice implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 1;

    public function __construct(public int $giftId)
    {
        $this->onQueue('alerts');
    }

    public function uniqueId(): string
    {
        return 'love:'.$this->giftId;
    }

    public function handle(NotificationService $n): void
    {
        $g = LoveGift::find($this->giftId);
        if (! $g || $g->is_sandbox || $g->status !== 'sent' || app()->environment('local', 'testing')) {
            return;
        }
        $url = $g->client->wp_profile_permalink ?: $g->client->wp_profile_url;
        $url = $url ? rtrim(explode('#', $url)[0], '/').'/#wallet-love' : '';
        $result = $n->sendSmsToClient($g->client, 'Someone sent you love · '.$g->currency.' '.$g->amount.'. Open Love received in Visibility & billing to read your note. '.($url ?: ''), ['notification_purpose' => 'send_love', 'source' => 'automated', 'dedupe_key' => 'love:'.$g->public_id]);
        $g->update(['metadata_json' => array_merge($g->metadata_json ?? [], ['notification_status' => $result['status'] ?? 'unknown'])]);
    }
}
