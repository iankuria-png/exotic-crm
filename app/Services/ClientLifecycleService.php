<?php

namespace App\Services;

use App\Exceptions\ClientLifecycleMutationException;
use App\Models\Client;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Support\ClientLifecycleState;
use App\Support\ClientLockReason;
use App\Support\CrmClientCloseReason;
use App\Support\LifecycleRestoreEligibility;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Orchestrates the Archived tier of the profile lifecycle (and un-archiving back
 * to Expired). Natural/manual expiry is handled by ExpiredSubscriptionReconciler;
 * a return to Active happens through subscription provisioning (which clears the
 * WordPress crm_lifecycle_state meta on activate). Removal reuses
 * ClientDeletionService via the existing destroy endpoint.
 *
 * Like the reconciler, this pushes the authoritative state to WordPress, re-mirrors
 * the profile, and records a timeline event. Audit logging is left to the caller
 * (mirrors ClientController::expireNow) so the service stays Request-free.
 */
class ClientLifecycleService
{
    public function __construct(
        private readonly ClientLifecycleMutationLock $lifecycleLock,
    ) {}

    /**
     * Move an Expired profile to Archived: still published & indexed, but excluded
     * from city/category listings. Idempotent when already archived.
     */
    public function archive(Client $client, ?int $actorId, string $trigger = 'manual'): Client
    {
        return $this->lifecycleLock->run(
            $client,
            fn () => $this->archiveLocked($client, $actorId, $trigger),
        );
    }

    private function archiveLocked(Client $client, ?int $actorId, string $trigger): Client
    {
        $client->refresh()->loadMissing('platform');

        if ($client->profile_removal_requested_at !== null) {
            throw new ClientLifecycleMutationException('This profile has a removal request and cannot be republished by lifecycle actions.', 'profile_removal_requested');
        }

        if ($client->deals()->currentlyActive()->exists()) {
            throw ClientLifecycleMutationException::paidEntitlement();
        }

        if ($client->lifecycle_state === ClientLifecycleState::ARCHIVED) {
            return $client;
        }

        if ($client->lifecycle_state !== ClientLifecycleState::EXPIRED) {
            throw new InvalidArgumentException('Only Expired profiles can be archived.');
        }

        return $this->transition(
            $client,
            ClientLifecycleState::ARCHIVED,
            ['lifecycle_archived_at' => now()],
            'profile_archived',
            ['trigger' => $trigger],
            $actorId,
        );
    }

    /**
     * Restore an Archived profile to Expired (back into listings) without granting
     * contact access. Idempotent when already expired.
     */
    public function unarchive(
        Client $client,
        ?int $actorId,
        ?CarbonInterface $archiveDeferredUntil = null,
        string $trigger = 'manual',
        ?int $recoveryRunId = null,
    ): Client {
        return $this->lifecycleLock->run(
            $client,
            fn () => $this->unarchiveLocked(
                $client,
                $actorId,
                $archiveDeferredUntil,
                $trigger,
                $recoveryRunId,
            ),
        );
    }

    private function unarchiveLocked(
        Client $client,
        ?int $actorId,
        ?CarbonInterface $archiveDeferredUntil,
        string $trigger,
        ?int $recoveryRunId,
    ): Client {
        $client->refresh()->loadMissing('platform');

        if ($client->profile_removal_requested_at !== null) {
            throw new ClientLifecycleMutationException('This profile has a removal request and cannot be republished by lifecycle actions.', 'profile_removal_requested');
        }

        if ($client->lifecycle_state === ClientLifecycleState::EXPIRED) {
            return $client;
        }

        if ($client->lifecycle_state !== ClientLifecycleState::ARCHIVED) {
            throw new InvalidArgumentException('Only Archived profiles can be un-archived.');
        }

        return $this->transition(
            $client,
            ClientLifecycleState::EXPIRED,
            [
                'lifecycle_archived_at' => null,
                'lifecycle_archive_deferred_until' => $archiveDeferredUntil,
            ],
            'profile_unarchived',
            array_filter([
                'trigger' => $trigger,
                'recovery_run_id' => $recoveryRunId,
                'archive_deferred_until' => optional($archiveDeferredUntil)->toDateTimeString(),
            ], static fn ($value) => $value !== null),
            $actorId,
        );
    }

    /**
     * What locking this profile would do, without writing anything. Powers the
     * bulk preview and guards the write itself.
     *
     * Actions: `lock` (public → Expired), `republish` (offline → published as
     * Expired, what SEO Recovery does), `already_locked`, or `blocked`.
     *
     * @return array{action: string, has_paid_plan: bool, code?: string, reason?: string}
     */
    public function lockPlan(Client $client): array
    {
        $client->loadMissing('platform');
        $hasPaidPlan = app(ActiveSubscriptionProfileRepairService::class)->hasFutureActiveDeal($client);
        $base = ['has_paid_plan' => $hasPaidPlan];
        $blocked = static fn (string $code, string $reason): array => $base + [
            'action' => 'blocked',
            'code' => $code,
            'reason' => $reason,
        ];

        if ((int) ($client->wp_post_id ?? 0) <= 0) {
            return $blocked('not_linked', 'Not linked to a WordPress profile.');
        }

        $platform = $client->platform ?? Platform::find((int) $client->platform_id);
        if (! $platform?->lifecycleEnabled()) {
            return $blocked('lifecycle_disabled', 'Locking needs the profile lifecycle switched on for this market.');
        }

        if ($client->profile_removal_requested_at !== null) {
            return $blocked('profile_removal_requested', 'A removal was requested for this profile.');
        }

        if ($client->isProfilePaused()) {
            return $blocked('paused', "Paused at the advertiser's request. Resume it first.");
        }

        $status = (string) $client->profile_status;

        if ($status === 'publish') {
            if ($client->isPubliclyRestricted()) {
                return $base + [
                    'action' => 'already_locked',
                    'reason' => $client->profile_locked_at !== null
                        ? 'Already locked by staff.'
                        : 'Already locked by its lifecycle (Expired or Archived).',
                ];
            }

            return $base + ['action' => 'lock'];
        }

        if ($status !== 'private' || $client->lifecycle_state === ClientLifecycleState::REMOVED) {
            return $blocked('not_public', 'Only public or offline profiles can be locked.');
        }

        // Republishing creates public content, so the SEO Recovery safety rules apply.
        if ((bool) $client->is_high_risk) {
            return $blocked('high_risk', 'High-risk profiles stay offline.');
        }

        if ($client->duplicate_of !== null) {
            return $blocked('duplicate', 'Marked as a duplicate of another profile.');
        }

        if ($client->closed_at !== null
            && in_array((string) $client->close_reason_code, LifecycleRestoreEligibility::BAD_CLOSE_REASONS, true)) {
            return $blocked('bad_close_reason', sprintf(
                'Case closed as "%s", so it stays offline.',
                CrmClientCloseReason::label((string) $client->close_reason_code),
            ));
        }

        if (! $hasPaidPlan
            && $client->first_activated_at === null
            && $client->churned_at === null
            && ! $client->deals()->exists()) {
            return $blocked('never_live', 'Never been live. Locking would publish a brand-new page.');
        }

        return $base + ['action' => 'republish'];
    }

    /**
     * Staff lock: hold the profile in Expired — published and indexed, contacts
     * hidden, editing locked — with a reason. A running paid plan keeps its
     * clock; background repairs leave the lock alone, a new activation ends it.
     */
    public function lock(Client $client, ?int $actorId, string $reasonCode, ?string $note = null): Client
    {
        if (! ClientLockReason::isValid($reasonCode)) {
            throw new InvalidArgumentException('Choose a reason for the lock.');
        }

        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null;
        if (ClientLockReason::requiresNote($reasonCode) && $note === null) {
            throw new InvalidArgumentException('Add a note explaining the lock.');
        }

        return $this->lifecycleLock->run(
            $client,
            fn () => $this->lockLocked($client, $actorId, $reasonCode, $note),
        );
    }

    private function lockLocked(Client $client, ?int $actorId, string $reasonCode, ?string $note): Client
    {
        $client->refresh()->loadMissing('platform');
        $plan = $this->lockPlan($client);

        if ($plan['action'] === 'already_locked') {
            return $client;
        }

        if ($plan['action'] === 'blocked') {
            throw new ClientLifecycleMutationException($plan['reason'], $plan['code']);
        }

        return $this->transition(
            $client,
            ClientLifecycleState::EXPIRED,
            [
                'profile_status' => 'publish',
                'lifecycle_expired_at' => now(),
                'lifecycle_archived_at' => null,
                'lifecycle_archive_deferred_until' => null,
                'profile_locked_at' => now(),
                'profile_locked_by' => $actorId,
                'profile_lock_reason_code' => $reasonCode,
                'profile_lock_reason_note' => $note,
            ],
            'profile_locked',
            array_filter([
                'reason_code' => $reasonCode,
                'reason' => ClientLockReason::label($reasonCode),
                'note' => $note,
                'mode' => $plan['action'],
                'had_paid_plan' => $plan['has_paid_plan'],
                'previous_status' => (string) $client->profile_status,
                'previous_lifecycle_state' => (string) ($client->lifecycle_state ?? ClientLifecycleState::ACTIVE),
            ], static fn ($value) => $value !== null),
            $actorId,
        );
    }

    /**
     * Lift a staff lock. With a running paid plan the profile returns to Active;
     * without one only the hold is lifted and it stays Expired until payment.
     */
    public function unlock(Client $client, ?int $actorId, ?string $note = null): Client
    {
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null;

        return $this->lifecycleLock->run(
            $client,
            fn () => $this->unlockLocked($client, $actorId, $note),
        );
    }

    private function unlockLocked(Client $client, ?int $actorId, ?string $note): Client
    {
        $client->refresh()->loadMissing('platform');

        if ($client->profile_locked_at === null) {
            throw new ClientLifecycleMutationException('This profile is not locked by staff.', 'not_locked');
        }

        $repair = app(ActiveSubscriptionProfileRepairService::class);
        $content = array_filter([
            'note' => $note,
            'lock_reason_code' => $client->profile_lock_reason_code,
            'locked_at' => optional($client->profile_locked_at)->toDateTimeString(),
        ], static fn ($value) => $value !== null);

        if ($client->profile_removal_requested_at === null && $repair->hasFutureActiveDeal($client)) {
            $row = $repair->repairClientHeld($client, 'profile_unlock');
            if (! in_array($row['action'] ?? null, ['repaired', 'already_ok'], true)) {
                throw new \RuntimeException('The paid plan could not be restored. Sync the profile and try again.');
            }

            $fresh = $client->fresh(['platform']) ?? $client;
            try {
                $fresh = (new ClientSyncService($fresh->platform))->syncOne((int) $fresh->wp_post_id);
            } catch (\Throwable $exception) {
                Log::warning('Profile unlock: confirming sync failed; WordPress already accepted Active.', [
                    'client_id' => $client->id,
                    'error' => $exception->getMessage(),
                ]);
            }
            app(ProfileBioScrubService::class)->restoreQuietly($fresh, $actorId);
            $content['outcome'] = 'active';
        } else {
            Client::withoutRetentionRefresh(function () use ($client): void {
                $client->forceFill(['profile_locked_at' => null])->save();
            });
            $fresh = $client;
            $content['outcome'] = 'stays_expired';
        }

        TimelineEvent::create([
            'platform_id' => (int) $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'event_type' => 'profile_unlocked',
            'actor_id' => $actorId,
            'content' => $content,
            'created_at' => now(),
        ]);

        return $fresh->fresh(['platform']) ?? $fresh;
    }

    /**
     * @param  array<string, mixed>  $columns  Extra client columns to set alongside lifecycle_state.
     * @param  array<string, mixed>  $eventContent  Extra timeline event content.
     */
    private function transition(
        Client $client,
        string $state,
        array $columns,
        string $eventType,
        array $eventContent,
        ?int $actorId,
    ): Client {
        $wpPostId = (int) ($client->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            throw new InvalidArgumentException('Client is not linked to a WordPress profile.');
        }

        $platform = $client->platform ?? Platform::findOrFail((int) $client->platform_id);

        if (! $platform->lifecycleEnabled()) {
            throw new InvalidArgumentException('The profile lifecycle policy is not enabled for this market.');
        }

        // 1. Publish the authoritative state to WordPress (keeps the post published).
        WpSyncService::forPlatform((int) $client->platform_id)->setLifecycleState($wpPostId, $state);

        // 2. Re-mirror, then stamp the lifecycle columns authoritatively.
        $synced = (new ClientSyncService($platform))->syncOne($wpPostId);
        Client::withoutRetentionRefresh(function () use ($synced, $state, $columns): void {
            $synced->forceFill(array_merge(['lifecycle_state' => $state], $columns))->save();
        });

        // 3. Keep the bio in step with the new state: scrub contact details when
        //    the profile is restricted, restore the original when it is not.
        //    Non-fatal so a bio failure never leaves the transition half-done.
        $scrubber = app(ProfileBioScrubService::class);
        if ($synced->isPubliclyRestricted()) {
            $scrubber->scrubQuietly($synced, $actorId);
        } else {
            $scrubber->restoreQuietly($synced, $actorId);
        }

        // 4. Audit trail.
        TimelineEvent::create([
            'platform_id' => (int) $synced->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $synced->id,
            'event_type' => $eventType,
            'actor_id' => $actorId,
            'content' => array_merge(['lifecycle_state' => $state], $eventContent),
            'created_at' => now(),
        ]);

        return $synced->fresh(['platform']);
    }
}
