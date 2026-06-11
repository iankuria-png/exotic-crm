<?php

namespace App\Services;

use App\Models\Client;
use App\Models\TimelineEvent;
use App\Support\CrmClientChurnReason;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ClientChurnStamper
{
    /**
     * Stamp churn on a client. Idempotent:
     * - If churned_at is already set and no newer active deal has intervened, just refreshes reason/source.
     * - Only stamps if the "lost-paid" condition holds: no active deal AND first_activated_at is set.
     *
     * @param Client $client
     * @param string $reasonCode  CrmClientChurnReason constant
     * @param string $source      One of: deal_cancelled, deal_expired, deal_deactivated, case_closed
     * @param Carbon|null $when   Timestamp of the churn event (defaults to now())
     */
    public function stamp(Client $client, string $reasonCode, string $source, ?Carbon $when = null): void
    {
        $client->refresh();

        // Only stamp lost-paid: client must have activated at least one deal
        if ($client->first_activated_at === null) {
            return;
        }

        // Verify no active deal currently exists
        $hasActiveDeal = $client->deals()->where('status', 'active')->exists();
        if ($hasActiveDeal) {
            return;
        }

        $churnedAt = $when ?? now();

        Client::withoutRetentionRefresh(function () use ($client, $reasonCode, $source, $churnedAt): void {
            $client->forceFill([
                'churned_at' => $churnedAt,
                'churn_reason_code' => $reasonCode,
                'churn_source' => $source,
            ])->save();
        });

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

    /**
     * Clear churn fields when a client is won back (new active deal, or manual mark).
     *
     * @param Client $client
     * @param string $reason  Human-readable reason for the timeline event
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
     *
     * @param Client $client
     */
    public function refreshFirstActivatedAt(Client $client): void
    {
        if ($client->first_activated_at !== null) {
            return; // Already set
        }

        $earliest = $client->deals()
            ->whereNotNull('activated_at')
            ->orderBy('activated_at', 'asc')
            ->value('activated_at');

        if ($earliest === null) {
            return;
        }

        Client::withoutRetentionRefresh(function () use ($client, $earliest): void {
            $client->forceFill(['first_activated_at' => $earliest])->save();
        });
    }
}
