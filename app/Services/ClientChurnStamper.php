<?php

namespace App\Services;

use App\Models\Client;
use App\Models\TimelineEvent;
use App\Support\CrmClientChurnReason;
use Carbon\Carbon;

class ClientChurnStamper
{
    /**
     * Stamp churn on a client. Idempotent:
     * - If churned_at is already set, preserve the original event time and refresh reason/source.
     * - Only stamps if the canonical "lost-paid" condition holds: paid history and inactive profile.
     *
     * @param  string  $reasonCode  CrmClientChurnReason constant
     * @param  string  $source  One of: profile_inactive, deal_cancelled, deal_expired, case_closed
     * @param  Carbon|null  $when  Timestamp of the churn event (defaults to now())
     */
    public function stamp(Client $client, string $reasonCode, string $source, ?Carbon $when = null): void
    {
        $client->refresh();

        if (! $this->hasPaidHistory($client) || $client->isActiveProfile()) {
            return;
        }

        $wasChurned = $client->churned_at !== null;
        $churnedAt = $client->churned_at ?? $when ?? now();

        Client::withoutRetentionRefresh(function () use ($client, $reasonCode, $source, $churnedAt): void {
            $client->forceFill([
                'churned_at' => $churnedAt,
                'churn_reason_code' => $reasonCode,
                'churn_source' => $source,
            ])->save();
        });

        if (! $wasChurned) {
            TimelineEvent::create([
                'platform_id' => (int) $client->platform_id,
                'entity_type' => 'client',
                'entity_id' => (int) $client->id,
                'event_type' => 'client_churned',
                'actor_id' => null,
                'content' => [
                    'reason_code' => $reasonCode,
                    'reason_label' => CrmClientChurnReason::label($reasonCode),
                    'source' => $source,
                    'churned_at' => $churnedAt->toDateTimeString(),
                ],
                'created_at' => now(),
            ]);
        }
    }

    public function syncFromProfileState(
        Client $client,
        string $reasonCode = CrmClientChurnReason::EXPIRED_UNRENEWED,
        string $source = 'profile_inactive',
        ?Carbon $when = null,
    ): void {
        $client->refresh();
        $this->refreshFirstActivatedAt($client);

        if ($client->isActiveProfile()) {
            $this->clear($client, 'profile_returned_active');

            return;
        }

        if ($client->churned_at !== null) {
            return;
        }

        $this->stamp($client, $reasonCode, $source, $when);
    }

    /**
     * Reconcile clients updated through query-builder upserts, which bypass model events.
     *
     * @param  array<int>  $clientIds
     */
    public function syncClientIds(array $clientIds): void
    {
        Client::query()
            ->whereKey(array_values(array_unique(array_map('intval', $clientIds))))
            ->chunkById(200, function ($clients): void {
                foreach ($clients as $client) {
                    $this->syncFromProfileState($client);
                }
            });
    }

    /**
     * Clear churn fields when a client is won back (new active deal, or manual mark).
     *
     * @param  string  $reason  Human-readable reason for the timeline event
     */
    public function clear(Client $client, string $reason = 'subscription_recovered'): void
    {
        $client->refresh();

        if ($client->churned_at === null) {
            return; // Already cleared — idempotent
        }

        $previousReason = $client->churn_reason_code;
        $previousSource = $client->churn_source;

        Client::withoutRetentionRefresh(function () use ($client): void {
            $client->forceFill([
                'churned_at' => null,
                'churn_reason_code' => null,
                'churn_source' => null,
            ])->save();
        });

        TimelineEvent::create([
            'platform_id' => (int) $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'event_type' => 'client_won_back',
            'actor_id' => null,
            'content' => [
                'reason' => $reason,
                'previous_churn_reason_code' => $previousReason,
                'previous_churn_source' => $previousSource,
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * Populate first_activated_at from the earliest deal's activated_at.
     * Idempotent — only sets if currently null.
     */
    public function refreshFirstActivatedAt(Client $client): void
    {
        if ($client->first_activated_at !== null) {
            return; // Already set
        }

        $earliest = $this->firstActivationAt($client);

        if ($earliest === null) {
            return;
        }

        Client::withoutRetentionRefresh(function () use ($client, $earliest): void {
            $client->forceFill(['first_activated_at' => $earliest])->save();
        });
    }

    public function firstActivationAt(Client $client): ?Carbon
    {
        $dealActivation = $client->deals()
            ->whereIn('status', ClientFunnelService::PAID_DEAL_STATUSES)
            ->selectRaw('MIN(COALESCE(activated_at, created_at)) as first_paid_at')
            ->value('first_paid_at');

        $paymentActivation = $client->payments()
            ->reportableSuccessful()
            ->selectRaw('MIN(COALESCE(completed_at, created_at)) as first_paid_at')
            ->value('first_paid_at');

        $earliest = collect([$dealActivation, $paymentActivation])
            ->filter()
            ->map(fn ($value) => Carbon::parse($value))
            ->sort()
            ->first();

        return $earliest instanceof Carbon ? $earliest : null;
    }

    private function hasPaidHistory(Client $client): bool
    {
        return ClientFunnelService::applyPaidHistory(
            Client::query()->whereKey($client->getKey())
        )->exists();
    }
}
