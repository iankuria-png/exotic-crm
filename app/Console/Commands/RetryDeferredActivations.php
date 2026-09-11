<?php

namespace App\Console\Commands;

use App\Models\Deal;
use App\Models\TimelineEvent;
use App\Services\MarketHealthService;
use App\Services\SubscriptionProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finishes subscriptions that were paid for while their market was unreachable.
 *
 * DealController commits the payment and parks the deal as `paid` rather than
 * rolling the sale back on an advertiser who has handed over money. This picks
 * those deals up and activates them once the market answers again.
 */
class RetryDeferredActivations extends Command
{
    protected $signature = 'crm:retry-deferred-activations
        {--limit=100 : Maximum deferred deals to attempt this run}
        {--deal= : Retry a single deal id, ignoring market health}';

    protected $description = 'Activate paid subscriptions whose activation was deferred while their market was down.';

    /**
     * After this many attempts the deal stops being retried automatically and
     * is left for a human. A market that has not answered in this many sweeps
     * is not going to be fixed by asking it again.
     */
    private const MAX_ATTEMPTS = 48;

    public function handle(
        SubscriptionProvisioningService $provisioning,
        MarketHealthService $health
    ): int {
        $single = $this->option('deal') !== null ? (int) $this->option('deal') : null;

        $deals = Deal::query()
            ->with(['client.platform', 'platform'])
            ->whereNotNull('activation_deferred_at')
            ->where('status', 'paid')
            ->when($single !== null, fn ($q) => $q->where('id', $single))
            ->when($single === null, fn ($q) => $q->where('activation_attempts', '<', self::MAX_ATTEMPTS))
            ->orderBy('activation_deferred_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($deals->isEmpty()) {
            $this->info('No deferred activations waiting.');

            return self::SUCCESS;
        }

        $activated = 0;
        $stillWaiting = 0;
        $gaveUp = 0;

        foreach ($deals as $deal) {
            $platformId = (int) $deal->platform_id;

            // Skip markets that are still gated, unless a human named this deal
            // explicitly — retrying a known-down market just burns the attempt
            // budget that protects the deal from being abandoned.
            if ($single === null && $this->marketStillDown($health, $platformId)) {
                $stillWaiting++;
                $this->line("  deal #{$deal->id}: market #{$platformId} still down, leaving it queued.");

                continue;
            }

            try {
                $provisioning->activateDeal($deal, [
                    'payment' => $deal->payment,
                    'payment_method' => $deal->payment?->payment_method,
                    'bypass_market_health' => true,
                    'emit_profile_activated_timeline' => true,
                ]);

                $deal->forceFill([
                    'activation_deferred_at' => null,
                    'activation_deferred_reason' => null,
                ])->save();

                TimelineEvent::create([
                    'platform_id' => $platformId,
                    'entity_type' => 'deal',
                    'entity_id' => (int) $deal->id,
                    'event_type' => 'activation_recovered',
                    'actor_id' => null,
                    'content' => [
                        'deal_id' => (int) $deal->id,
                        'attempts' => (int) $deal->activation_attempts,
                    ],
                    'created_at' => now(),
                ]);

                $activated++;
                $this->info("  deal #{$deal->id}: activated.");
            } catch (Throwable $e) {
                $deal->forceFill([
                    'activation_attempts' => (int) $deal->activation_attempts + 1,
                    'activation_deferred_reason' => mb_strimwidth($e->getMessage(), 0, 250, '…'),
                ])->save();

                if ((int) $deal->activation_attempts >= self::MAX_ATTEMPTS) {
                    $gaveUp++;
                    // Error level so it reaches the Errors tab: a paid advertiser
                    // who is still not live needs a person, not another sweep.
                    Log::error('Deferred activation gave up after repeated attempts', [
                        'deal_id' => $deal->id,
                        'client_id' => $deal->client_id,
                        'platform_id' => $platformId,
                        'attempts' => (int) $deal->activation_attempts,
                        'error' => $e->getMessage(),
                    ]);
                } else {
                    $stillWaiting++;
                }

                $this->error("  deal #{$deal->id}: {$e->getMessage()}");
            }
        }

        $this->info(sprintf(
            'Done. activated=%d still_waiting=%d gave_up=%d',
            $activated,
            $stillWaiting,
            $gaveUp
        ));

        return self::SUCCESS;
    }

    private function marketStillDown(MarketHealthService $health, int $platformId): bool
    {
        $snapshot = $health->cachedPlatformHealth($platformId);

        if (! $snapshot) {
            return false;
        }

        return $health->shouldFailFast(
            $snapshot['health_status'] ?? null,
            (int) ($snapshot['health_consecutive_failures'] ?? 0)
        );
    }
}
