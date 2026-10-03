<?php

namespace App\Jobs;

use App\Models\MonetizationAutomationItem;
use App\Services\Monetization\ComplimentaryPassService;
use App\Services\Monetization\ExpiryAutomationService;
use App\Services\Monetization\TeaserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One idempotent monetization automation operation: an expired profile's media
 * conversion, one complimentary selling pass or one video's card preview. Failures stay on the item for the
 * in-place retry action instead of silently re-running.
 */
class ProcessMonetizationAutomationItem implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 600;

    public function __construct(public int $itemId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'monetize-automation-item:'.$this->itemId;
    }

    public function handle(): void
    {
        $item = MonetizationAutomationItem::find($this->itemId);
        if (! $item || in_array($item->status, ['succeeded', 'skipped', 'running'], true)) {
            return;
        }
        $item->update(['status' => 'running', 'attempts' => $item->attempts + 1]);
        $expiry = app(ExpiryAutomationService::class);
        try {
            if ($item->kind === 'expiry_media') {
                $expiry->process($item);
            } elseif ($item->kind === 'teaser_generate') {
                app(TeaserService::class)->processItem($item);
            } else {
                app(ComplimentaryPassService::class)->processItem($item);
            }
        } catch (Throwable $e) {
            Log::warning('Monetization automation item failed.', ['item_id' => $item->id, 'error' => $e->getMessage()]);
            $expiry->finish($item->fresh(), 'failed', 'unexpected_error', ['message' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }
}
