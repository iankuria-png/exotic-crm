<?php

namespace App\Services;

use App\Models\CustomerAccount;
use App\Models\CustomerActivityEvent;
use App\Models\CustomerSavedObject;
use App\Models\Platform;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Owns durable customer (site member) product state.
 *
 * Identity is never taken from a client-supplied field on trust: WordPress
 * derives `wp_user_id` and `wp_role` from its own session and signs them into
 * the request body, and `platform_id` comes from the HMAC middleware. This
 * service still re-validates both before it will write anything.
 */
class CustomerProductService
{
    /** A member cannot save more than this. Guards against runaway clients. */
    public const MAX_SAVED_OBJECTS = 500;

    /** Only this WordPress role gets a customer workspace. */
    public const REQUIRED_WP_ROLE = 'member';

    /** Largest merge batch accepted from a browser-local store. */
    public const MAX_MERGE_BATCH = 100;

    public function resolveAccount(Platform $platform, array $identity): CustomerAccount
    {
        $wpUserId = (int) ($identity['wp_user_id'] ?? 0);
        if ($wpUserId < 1) {
            throw new InvalidArgumentException('A WordPress user id is required.');
        }

        $role = strtolower(trim((string) ($identity['wp_role'] ?? '')));
        if ($role !== self::REQUIRED_WP_ROLE) {
            throw new InvalidArgumentException('This WordPress account is not a member account.');
        }

        $displayName = $this->trimOrNull($identity['display_name'] ?? null, 190);
        $email = $this->trimOrNull($identity['email'] ?? null, 190);
        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = null;
        }

        $now = Carbon::now();

        $account = CustomerAccount::query()->firstOrNew([
            'platform_id' => $platform->id,
            'wp_user_id' => $wpUserId,
        ]);

        $isNew = ! $account->exists;
        if ($isNew) {
            $account->first_seen_at = $now;
        }

        // WordPress is the source of truth for name and email. Every signed call
        // carries the current values, so the row self-heals instead of drifting.
        $account->display_name = $displayName;
        $account->email = $email;
        $account->email_hash = CustomerAccount::hashEmail($email);
        $account->last_seen_at = $now;
        $account->save();

        if ($isNew) {
            $this->recordEvent($account, CustomerActivityEvent::EVENT_ACCOUNT_LINKED);
        }

        return $account;
    }

    /** @return int[] */
    public function savedProfileIds(CustomerAccount $account): array
    {
        return CustomerSavedObject::query()
            ->where('customer_account_id', $account->id)
            ->where('object_type', CustomerSavedObject::TYPE_PROFILE)
            ->orderByDesc('saved_at')
            ->orderByDesc('id')
            ->pluck('object_ref')
            ->map(static fn ($ref) => (int) $ref)
            ->all();
    }

    public function savedCount(CustomerAccount $account): int
    {
        return CustomerSavedObject::query()
            ->where('customer_account_id', $account->id)
            ->count();
    }

    /**
     * Save one profile. Returns true when a new row was created, false when it
     * was already saved. Idempotent either way.
     */
    public function save(CustomerAccount $account, int $objectRef, string $source = CustomerSavedObject::SOURCE_WORKSPACE): bool
    {
        $objectRef = (int) $objectRef;
        if ($objectRef < 1) {
            throw new InvalidArgumentException('A profile id is required.');
        }

        $existing = CustomerSavedObject::query()
            ->where('customer_account_id', $account->id)
            ->where('object_type', CustomerSavedObject::TYPE_PROFILE)
            ->where('object_ref', $objectRef)
            ->first();

        if ($existing) {
            return false;
        }

        if ($this->savedCount($account) >= self::MAX_SAVED_OBJECTS) {
            throw new InvalidArgumentException('Saved list is full.');
        }

        CustomerSavedObject::query()->create([
            'customer_account_id' => $account->id,
            'platform_id' => $account->platform_id,
            'object_type' => CustomerSavedObject::TYPE_PROFILE,
            'object_ref' => $objectRef,
            'source' => $source,
            'saved_at' => Carbon::now(),
        ]);

        $this->recordEvent($account, CustomerActivityEvent::EVENT_SAVE_ADDED, $objectRef, ['source' => $source]);

        return true;
    }

    /** Returns true when a row was actually removed. */
    public function unsave(CustomerAccount $account, int $objectRef): bool
    {
        $objectRef = (int) $objectRef;
        if ($objectRef < 1) {
            throw new InvalidArgumentException('A profile id is required.');
        }

        $deleted = CustomerSavedObject::query()
            ->where('customer_account_id', $account->id)
            ->where('object_type', CustomerSavedObject::TYPE_PROFILE)
            ->where('object_ref', $objectRef)
            ->delete();

        if ($deleted > 0) {
            $this->recordEvent($account, CustomerActivityEvent::EVENT_SAVE_REMOVED, $objectRef);
        }

        return $deleted > 0;
    }

    /**
     * Merge a batch of profile ids into the account, skipping anything already
     * saved. Used for the legacy WordPress backfill and for folding a signed-out
     * browser-local list into the account on first authenticated load.
     *
     * @param  int[]  $objectRefs
     * @return int Number of rows actually created.
     */
    public function merge(CustomerAccount $account, array $objectRefs, string $source): int
    {
        $objectRefs = array_values(array_unique(array_filter(
            array_map('intval', $objectRefs),
            static fn (int $ref) => $ref > 0
        )));

        if (empty($objectRefs)) {
            return 0;
        }

        $objectRefs = array_slice($objectRefs, 0, self::MAX_MERGE_BATCH);

        $existing = CustomerSavedObject::query()
            ->where('customer_account_id', $account->id)
            ->where('object_type', CustomerSavedObject::TYPE_PROFILE)
            ->whereIn('object_ref', $objectRefs)
            ->pluck('object_ref')
            ->map(static fn ($ref) => (int) $ref)
            ->all();

        $fresh = array_values(array_diff($objectRefs, $existing));
        if (empty($fresh)) {
            return 0;
        }

        $room = max(0, self::MAX_SAVED_OBJECTS - $this->savedCount($account));
        $fresh = array_slice($fresh, 0, $room);
        if (empty($fresh)) {
            return 0;
        }

        $now = Carbon::now();
        $rows = array_map(static fn (int $ref) => [
            'customer_account_id' => $account->id,
            'platform_id' => $account->platform_id,
            'object_type' => CustomerSavedObject::TYPE_PROFILE,
            'object_ref' => $ref,
            'source' => $source,
            'saved_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $fresh);

        DB::table('customer_saved_objects')->insert($rows);

        $this->recordEvent($account, CustomerActivityEvent::EVENT_SAVES_MERGED, null, [
            'source' => $source,
            'merged' => count($fresh),
        ]);

        return count($fresh);
    }

    public function recordEvent(
        CustomerAccount $account,
        string $eventType,
        ?int $objectRef = null,
        array $context = []
    ): CustomerActivityEvent {
        return CustomerActivityEvent::query()->create([
            'customer_account_id' => $account->id,
            'platform_id' => $account->platform_id,
            'event_type' => $eventType,
            'object_type' => $objectRef !== null ? CustomerSavedObject::TYPE_PROFILE : null,
            'object_ref' => $objectRef,
            'occurred_at' => Carbon::now(),
            'context_json' => empty($context) ? null : $context,
        ]);
    }

    /**
     * Delete every trace of a customer. Saved objects and activity events go with
     * the account row through the foreign key cascade.
     */
    public function forget(Platform $platform, int $wpUserId): bool
    {
        $wpUserId = (int) $wpUserId;
        if ($wpUserId < 1) {
            throw new InvalidArgumentException('A WordPress user id is required.');
        }

        $account = CustomerAccount::query()
            ->where('platform_id', $platform->id)
            ->where('wp_user_id', $wpUserId)
            ->first();

        if (! $account) {
            return false;
        }

        // Explicit child deletes: SQLite in tests does not always honour the
        // cascade, and being explicit keeps the intent readable in production.
        CustomerSavedObject::query()->where('customer_account_id', $account->id)->delete();
        CustomerActivityEvent::query()->where('customer_account_id', $account->id)->delete();
        $account->delete();

        return true;
    }

    private function trimOrNull(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
