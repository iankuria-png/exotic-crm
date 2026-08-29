<?php

namespace App\Services;

use App\Models\CustomerAccount;
use App\Models\CustomerActivityEvent;
use App\Models\CustomerCompareItem;
use App\Models\CustomerCompareSet;
use App\Models\CustomerFollow;
use App\Models\CustomerReachabilityFeedback;
use App\Models\CustomerRecentView;
use App\Models\CustomerSavedObject;
use App\Models\CustomerSavedSearch;
use App\Models\Client;
use App\Models\CustomerUnlockClaim;
use App\Models\Platform;
use App\Models\VisitorContactUnlock;
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

    /** The compare tray holds four. A fifth profile is refused, not rotated. */
    public const MAX_COMPARE_ITEMS = 4;

    /** Recent views returned to the workspace in one read. */
    public const RECENT_VIEWS_PAGE = 60;

    /** Members can keep a practical number of saved discovery routes. */
    public const MAX_SAVED_SEARCHES = 50;

    /** Unlock claims returned to the workspace in one read. */
    public const UNLOCK_CLAIMS_PAGE = 60;

    /** A guest reveal can be claimed only while the handoff is still fresh. */
    public const UNLOCK_CLAIM_HANDOFF_MINUTES = 60;

    /**
     * Per-request memos.
     *
     * Every action returns the workspace summary, so without these the compare
     * set is resolved twice and the recent-view count is counted twice on a
     * single request.
     *
     * The container hands out the same service instance for more than one
     * request, so these are NOT safe to leave standing: they are cleared in
     * `resolveAccount()`, which every request calls exactly once before any
     * other method. Anything that mutates the underlying rows also clears the
     * affected memo, so a single request stays self-consistent.
     *
     * @var array<int, \App\Models\CustomerCompareSet|false>
     */
    private array $compareSetMemo = [];

    /** @var array<int, int> */
    private array $recentCountMemo = [];

    /** @var array<int, \Illuminate\Support\Collection<int, CustomerCompareItem>> */
    private array $compareItemsMemo = [];

    /** @var array<int, \Illuminate\Support\Carbon|null> */
    private array $previousLastSeenMemo = [];

    public function resolveAccount(Platform $platform, array $identity): CustomerAccount
    {
        // The request boundary. Clearing here is what makes the memos below
        // safe on a service instance the container reuses between requests.
        $this->forgetMemos();

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
        $previousLastSeen = $account->exists ? $account->last_seen_at : null;
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

        $this->previousLastSeenMemo[(int) $account->id] = $previousLastSeen;

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

    public function previousLastSeenAt(CustomerAccount $account): ?Carbon
    {
        $key = (int) $account->id;

        return $this->previousLastSeenMemo[$key] ?? null;
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

    // ------------------------------------------------------------ recent views

    /**
     * Recent views, newest first.
     *
     * @return array<int, array{object_ref:int, view_count:int, last_viewed_at:string}>
     */
    public function recentViews(CustomerAccount $account, int $limit = self::RECENT_VIEWS_PAGE): array
    {
        $limit = max(1, min($limit, CustomerRecentView::MAX_PER_ACCOUNT));

        // Ordered by the monotonic view counter, not by `last_viewed_at`: two
        // profiles opened in the same second would otherwise tie and fall back
        // to creation order.
        return CustomerRecentView::query()
            ->where('customer_account_id', $account->id)
            ->where('object_type', CustomerRecentView::TYPE_PROFILE)
            ->orderByDesc('view_seq')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static fn (CustomerRecentView $view) => [
                'object_ref' => (int) $view->object_ref,
                'view_count' => (int) $view->view_count,
                'last_viewed_at' => $view->last_viewed_at?->toIso8601String(),
            ])
            ->all();
    }

    public function recentViewCount(CustomerAccount $account): int
    {
        $key = (int) $account->id;

        if (!array_key_exists($key, $this->recentCountMemo)) {
            $this->recentCountMemo[$key] = CustomerRecentView::query()
                ->where('customer_account_id', $account->id)
                ->count();
        }

        return $this->recentCountMemo[$key];
    }

    /**
     * Record that the member opened a profile. Returns true when this is the
     * first view of that profile, false when an existing row was bumped.
     */
    public function recordView(CustomerAccount $account, int $objectRef): bool
    {
        $objectRef = (int) $objectRef;
        if ($objectRef < 1) {
            throw new InvalidArgumentException('A profile id is required.');
        }

        $now = Carbon::now();

        $existing = CustomerRecentView::query()
            ->where('customer_account_id', $account->id)
            ->where('object_type', CustomerRecentView::TYPE_PROFILE)
            ->where('object_ref', $objectRef)
            ->first();

        $nextSeq = ((int) CustomerRecentView::query()
            ->where('customer_account_id', $account->id)
            ->max('view_seq')) + 1;

        if ($existing) {
            $existing->view_count = (int) $existing->view_count + 1;
            $existing->view_seq = $nextSeq;
            $existing->last_viewed_at = $now;
            $existing->save();

            // Row count is unchanged, so the memo stays valid and no trim is
            // needed: a repeat view can never cross the per-account cap.
            return false;
        }

        CustomerRecentView::query()->create([
            'customer_account_id' => $account->id,
            'platform_id' => $account->platform_id,
            'object_type' => CustomerRecentView::TYPE_PROFILE,
            'object_ref' => $objectRef,
            'view_count' => 1,
            'view_seq' => $nextSeq,
            'first_viewed_at' => $now,
            'last_viewed_at' => $now,
        ]);

        // The insert added exactly one row, so the count is known without a
        // second COUNT query.
        $key = (int) $account->id;
        if (array_key_exists($key, $this->recentCountMemo)) {
            $this->recentCountMemo[$key]++;
        }

        $this->trimRecentViews($account);

        return true;
    }

    /** Clear-history. Returns the number of rows removed. */
    public function clearRecentViews(CustomerAccount $account): int
    {
        $deleted = CustomerRecentView::query()
            ->where('customer_account_id', $account->id)
            ->delete();

        $this->recentCountMemo[(int) $account->id] = 0;

        if ($deleted > 0) {
            $this->recordEvent($account, CustomerActivityEvent::EVENT_VIEWS_CLEARED, null, [
                'cleared' => $deleted,
            ]);
        }

        return $deleted;
    }

    /** Drop every per-request memo. Called at the start of each request. */
    private function forgetMemos(): void
    {
        $this->compareSetMemo = [];
        $this->compareItemsMemo = [];
        $this->recentCountMemo = [];
        $this->previousLastSeenMemo = [];
    }

    /** Keep the newest MAX_PER_ACCOUNT rows so one member cannot grow forever. */
    private function trimRecentViews(CustomerAccount $account): void
    {
        $overflow = $this->recentViewCount($account) - CustomerRecentView::MAX_PER_ACCOUNT;
        if ($overflow < 1) {
            return;
        }

        unset($this->recentCountMemo[(int) $account->id]);

        $doomed = CustomerRecentView::query()
            ->where('customer_account_id', $account->id)
            ->orderBy('view_seq')
            ->orderBy('id')
            ->limit($overflow)
            ->pluck('id')
            ->all();

        if (! empty($doomed)) {
            CustomerRecentView::query()->whereIn('id', $doomed)->delete();
        }
    }

    // ----------------------------------------------------------------- compare

    /**
     * The member's active compare set, in tray order.
     *
     * @return int[]
     */
    public function compareProfileIds(CustomerAccount $account): array
    {
        return $this->compareItems($account)
            ->map(static fn (CustomerCompareItem $item) => (int) $item->object_ref)
            ->values()
            ->all();
    }

    /**
     * The tray's items, loaded once per request.
     *
     * The tray holds at most four rows, so loading the collection is cheaper
     * than the COUNT and MAX(position) aggregates it replaces — and the same
     * rows then serve the workspace summary without a third query.
     *
     * @return \Illuminate\Support\Collection<int, CustomerCompareItem>
     */
    private function compareItems(CustomerAccount $account)
    {
        $key = (int) $account->id;

        if (!array_key_exists($key, $this->compareItemsMemo)) {
            $set = $this->findCompareSet($account);

            $this->compareItemsMemo[$key] = $set
                ? CustomerCompareItem::query()
                    ->where('compare_set_id', $set->id)
                    ->where('object_type', CustomerCompareItem::TYPE_PROFILE)
                    ->orderBy('position')
                    ->orderBy('id')
                    ->get()
                : collect();
        }

        return $this->compareItemsMemo[$key];
    }

    /**
     * Add a profile to the tray. Returns true when a row was created, false when
     * it was already there. Throws when the tray is full — a fifth profile is
     * refused rather than silently displacing one of the four.
     */
    public function addToCompare(CustomerAccount $account, int $objectRef): bool
    {
        $objectRef = (int) $objectRef;
        if ($objectRef < 1) {
            throw new InvalidArgumentException('A profile id is required.');
        }

        $set = $this->compareSet($account);
        $items = $this->compareItems($account);

        if ($items->contains(static fn (CustomerCompareItem $item) => (int) $item->object_ref === $objectRef)) {
            $this->touchCompareSet($set);

            return false;
        }

        if ($items->count() >= self::MAX_COMPARE_ITEMS) {
            throw new InvalidArgumentException('Compare holds four. Remove one first.');
        }

        $position = (int) $items->max('position');

        CustomerCompareItem::query()->create([
            'compare_set_id' => $set->id,
            'customer_account_id' => $account->id,
            'platform_id' => $account->platform_id,
            'object_type' => CustomerCompareItem::TYPE_PROFILE,
            'object_ref' => $objectRef,
            'position' => $position + 1,
            'added_at' => Carbon::now(),
        ]);

        unset($this->compareItemsMemo[(int) $account->id]);
        $this->touchCompareSet($set);
        $this->recordEvent($account, CustomerActivityEvent::EVENT_COMPARE_ADDED, $objectRef);

        return true;
    }

    /** Returns true when a row was actually removed. */
    public function removeFromCompare(CustomerAccount $account, int $objectRef): bool
    {
        $objectRef = (int) $objectRef;
        if ($objectRef < 1) {
            throw new InvalidArgumentException('A profile id is required.');
        }

        $set = $this->findCompareSet($account);
        if (! $set) {
            return false;
        }

        $deleted = CustomerCompareItem::query()
            ->where('compare_set_id', $set->id)
            ->where('object_type', CustomerCompareItem::TYPE_PROFILE)
            ->where('object_ref', $objectRef)
            ->delete();

        unset($this->compareItemsMemo[(int) $account->id]);

        // The set header survives an empty tray on purpose: it carries the
        // "last update" timestamp that retention is measured from.
        $this->touchCompareSet($set);

        if ($deleted > 0) {
            $this->recordEvent($account, CustomerActivityEvent::EVENT_COMPARE_REMOVED, $objectRef);
        }

        return $deleted > 0;
    }

    /** Empty the tray. Returns the number of rows removed. */
    public function clearCompare(CustomerAccount $account): int
    {
        $set = $this->findCompareSet($account);
        if (! $set) {
            return 0;
        }

        $deleted = CustomerCompareItem::query()->where('compare_set_id', $set->id)->delete();
        unset($this->compareItemsMemo[(int) $account->id]);
        $this->touchCompareSet($set);

        if ($deleted > 0) {
            $this->recordEvent($account, CustomerActivityEvent::EVENT_COMPARE_CLEARED, null, [
                'cleared' => $deleted,
            ]);
        }

        return $deleted;
    }

    // ---------------------------------------------------------------- follows

    /** @return int[] */
    public function followIds(CustomerAccount $account, string $type): array
    {
        $type = $this->normalizeFollowType($type);

        return CustomerFollow::query()
            ->where('customer_account_id', $account->id)
            ->where('follow_type', $type)
            ->orderByDesc('followed_at')
            ->orderByDesc('id')
            ->pluck('object_ref')
            ->map(static fn ($ref) => (int) $ref)
            ->all();
    }

    public function followCount(CustomerAccount $account): int
    {
        return CustomerFollow::query()
            ->where('customer_account_id', $account->id)
            ->count();
    }

    public function follow(CustomerAccount $account, string $type, int $objectRef): bool
    {
        $type = $this->normalizeFollowType($type);
        $objectRef = $this->normalizePositiveRef($objectRef, $type === CustomerFollow::TYPE_PROFILE ? 'A profile id is required.' : 'A location id is required.');

        $existing = CustomerFollow::query()
            ->where('customer_account_id', $account->id)
            ->where('follow_type', $type)
            ->where('object_ref', $objectRef)
            ->first();

        if ($existing) {
            return false;
        }

        CustomerFollow::query()->create([
            'customer_account_id' => $account->id,
            'platform_id' => $account->platform_id,
            'follow_type' => $type,
            'object_ref' => $objectRef,
            'source' => CustomerFollow::SOURCE_WORKSPACE,
            'followed_at' => Carbon::now(),
        ]);

        $this->recordEvent($account, CustomerActivityEvent::EVENT_FOLLOW_ADDED, $objectRef, [
            'follow_type' => $type,
        ]);

        return true;
    }

    public function unfollow(CustomerAccount $account, string $type, int $objectRef): bool
    {
        $type = $this->normalizeFollowType($type);
        $objectRef = $this->normalizePositiveRef($objectRef, $type === CustomerFollow::TYPE_PROFILE ? 'A profile id is required.' : 'A location id is required.');

        $deleted = CustomerFollow::query()
            ->where('customer_account_id', $account->id)
            ->where('follow_type', $type)
            ->where('object_ref', $objectRef)
            ->delete();

        if ($deleted > 0) {
            $this->recordEvent($account, CustomerActivityEvent::EVENT_FOLLOW_REMOVED, $objectRef, [
                'follow_type' => $type,
            ]);
        }

        return $deleted > 0;
    }

    // ---------------------------------------------------------- saved searches

    /**
     * @return array<int,array{id:int,route_family:string,route_value:string,refinements:array<string,mixed>,label:?string,saved_at:?string}>
     */
    public function savedSearches(CustomerAccount $account): array
    {
        return CustomerSavedSearch::query()
            ->where('customer_account_id', $account->id)
            ->orderByDesc('saved_at')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (CustomerSavedSearch $search) => [
                'id' => (int) $search->id,
                'route_family' => (string) $search->route_family,
                'route_value' => (string) $search->route_value,
                'refinements' => is_array($search->refinements_json) ? $search->refinements_json : [],
                'label' => $search->label,
                'saved_at' => $search->saved_at?->toIso8601String(),
            ])
            ->all();
    }

    public function savedSearchCount(CustomerAccount $account): int
    {
        return CustomerSavedSearch::query()
            ->where('customer_account_id', $account->id)
            ->count();
    }

    public function saveSearch(CustomerAccount $account, string $routeFamily, string $routeValue, array $refinements = [], ?string $label = null): bool
    {
        $routeFamily = $this->normalizeRoutePart($routeFamily, 80, 'A discovery route is required.');
        $routeValue = $this->normalizeRoutePart($routeValue, 190, 'A discovery route is required.');
        $refinements = $this->normalizeRefinements($refinements);
        $hash = hash('sha256', json_encode($refinements));
        $label = $this->trimOrNull($label, 190);

        $existing = CustomerSavedSearch::query()
            ->where('customer_account_id', $account->id)
            ->where('route_family', $routeFamily)
            ->where('route_value', $routeValue)
            ->where('refinement_hash', $hash)
            ->first();

        if ($existing) {
            $existing->saved_at = Carbon::now();
            if ($label !== null) {
                $existing->label = $label;
            }
            $existing->save();

            return false;
        }

        if ($this->savedSearchCount($account) >= self::MAX_SAVED_SEARCHES) {
            throw new InvalidArgumentException('Saved searches are full.');
        }

        CustomerSavedSearch::query()->create([
            'customer_account_id' => $account->id,
            'platform_id' => $account->platform_id,
            'route_family' => $routeFamily,
            'route_value' => $routeValue,
            'refinement_hash' => $hash,
            'refinements_json' => $refinements,
            'label' => $label,
            'saved_at' => Carbon::now(),
        ]);

        $this->recordEvent($account, CustomerActivityEvent::EVENT_SEARCH_SAVED, null, [
            'route_family' => $routeFamily,
            'route_value' => $routeValue,
        ]);

        return true;
    }

    public function removeSavedSearch(CustomerAccount $account, int $savedSearchId): bool
    {
        $savedSearchId = $this->normalizePositiveRef($savedSearchId, 'A saved search id is required.');

        $search = CustomerSavedSearch::query()
            ->where('customer_account_id', $account->id)
            ->where('id', $savedSearchId)
            ->first();

        if (! $search) {
            return false;
        }

        $context = [
            'route_family' => (string) $search->route_family,
            'route_value' => (string) $search->route_value,
        ];
        $search->delete();

        $this->recordEvent($account, CustomerActivityEvent::EVENT_SEARCH_REMOVED, null, $context);

        return true;
    }

    // ----------------------------------------------------------- unlock claims

    public function unlockClaimCount(CustomerAccount $account): int
    {
        return CustomerUnlockClaim::query()
            ->where('customer_account_id', $account->id)
            ->count();
    }

    /**
     * Claimed unlocks, newest first. Contact details are intentionally omitted:
     * the member reveals a current contact through `revealClaimContact()`.
     *
     * @return array<int,array<string,mixed>>
     */
    public function unlockClaims(CustomerAccount $account, int $limit = self::UNLOCK_CLAIMS_PAGE): array
    {
        $limit = max(1, min($limit, self::UNLOCK_CLAIMS_PAGE));

        return CustomerUnlockClaim::query()
            ->with([
                'client:id,name,wp_post_id,wp_profile_permalink,main_image_url,display_image_url,city',
                'visitorUnlock:id,status,expires_at,last_revealed_at,scope',
            ])
            ->where('customer_account_id', $account->id)
            ->orderByDesc('claimed_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (CustomerUnlockClaim $claim) => $this->serializeUnlockClaim($claim))
            ->all();
    }

    public function claimUnlock(
        CustomerAccount $account,
        string $publicToken,
        string $sessionProof,
        int $targetWpPostId,
        string $source
    ): CustomerUnlockClaim {
        $publicToken = trim($publicToken);
        $sessionProof = trim($sessionProof);
        $targetWpPostId = $this->normalizePositiveRef($targetWpPostId, 'A profile id is required.');
        $source = in_array($source, [CustomerUnlockClaim::SOURCE_LOGGED_IN_REVEAL, CustomerUnlockClaim::SOURCE_POST_UNLOCK_ACCOUNT], true)
            ? $source
            : CustomerUnlockClaim::SOURCE_POST_UNLOCK_ACCOUNT;

        if ($publicToken === '' || $sessionProof === '') {
            throw new InvalidArgumentException("This one's not linked to your account.");
        }

        $unlock = VisitorContactUnlock::query()
            ->where('platform_id', $account->platform_id)
            ->where('public_token_hash', $this->hashToken($publicToken))
            ->first();

        if (! $unlock || ! hash_equals((string) $unlock->session_token_hash, $this->hashToken($sessionProof))) {
            throw new InvalidArgumentException("This one's not linked to your account.");
        }

        if (! $unlock->isActive() || ! $unlock->last_revealed_at || $unlock->last_revealed_at->lt(Carbon::now()->subMinutes(self::UNLOCK_CLAIM_HANDOFF_MINUTES))) {
            throw new InvalidArgumentException("This one's not linked to your account.");
        }

        $target = Client::query()
            ->where('platform_id', $account->platform_id)
            ->where('wp_post_id', $targetWpPostId)
            ->first();

        if (! $target || ! $this->canRevealTarget($unlock, $target)) {
            throw new InvalidArgumentException("This one's not linked to your account.");
        }

        $claim = CustomerUnlockClaim::query()
            ->where('visitor_contact_unlock_id', $unlock->id)
            ->where('wp_post_id', $targetWpPostId)
            ->first();

        if ($claim && $claim->customer_account_id !== null && (int) $claim->customer_account_id !== (int) $account->id) {
            throw new InvalidArgumentException("This one's not linked to your account.");
        }

        $created = ! $claim;
        if ($created) {
            $claim = new CustomerUnlockClaim([
                'visitor_contact_unlock_id' => (int) $unlock->id,
                'wp_post_id' => $targetWpPostId,
                'claimed_at' => Carbon::now(),
            ]);
        }

        $claim->fill([
            'customer_account_id' => (int) $account->id,
            'platform_id' => (int) $account->platform_id,
            'client_id' => (int) $target->id,
            'scope' => (string) $unlock->scope,
            'status' => $this->claimStatus($unlock),
            'expires_at' => $unlock->expires_at,
            'last_revealed_at' => $unlock->last_revealed_at,
            'source' => $source,
        ]);
        $claim->save();

        if ($created) {
            $this->recordEvent($account, CustomerActivityEvent::EVENT_UNLOCK_CLAIMED, $targetWpPostId, [
                'visitor_contact_unlock_id' => (int) $unlock->id,
                'source' => $source,
                'scope' => (string) $unlock->scope,
            ]);
        }

        return $claim->fresh(['client', 'visitorUnlock']);
    }

    public function serializeClaimForResponse(CustomerUnlockClaim $claim): array
    {
        return $this->serializeUnlockClaim($claim);
    }

    public function revealClaimContact(CustomerAccount $account, int $claimId): array
    {
        $claim = CustomerUnlockClaim::query()
            ->with(['client', 'visitorUnlock'])
            ->where('customer_account_id', $account->id)
            ->where('id', $this->normalizePositiveRef($claimId, 'An unlock claim id is required.'))
            ->first();

        if (! $claim || ! $claim->client || ! $claim->visitorUnlock || ! $claim->visitorUnlock->isActive()) {
            throw new InvalidArgumentException('This contact is no longer unlocked.');
        }

        $claim->forceFill([
            'status' => CustomerUnlockClaim::STATUS_ACTIVE,
            'expires_at' => $claim->visitorUnlock->expires_at,
            'last_revealed_at' => Carbon::now(),
        ])->save();

        $client = $claim->client;

        return [
            'success' => true,
            'claim' => $this->serializeUnlockClaim($claim->fresh(['client', 'visitorUnlock'])),
            'contact' => [
                'phone' => (string) $client->phone_normalized,
                'whatsapp' => (string) $client->phone_normalized,
                'email' => (string) $client->email,
            ],
        ];
    }

    public function submitReachabilityFeedback(CustomerAccount $account, int $claimId, string $outcome, ?string $note = null): CustomerReachabilityFeedback
    {
        $claim = CustomerUnlockClaim::query()
            ->with(['visitorUnlock'])
            ->where('customer_account_id', $account->id)
            ->where('id', $this->normalizePositiveRef($claimId, 'An unlock claim id is required.'))
            ->first();

        if (! $claim || ! $claim->visitorUnlock || ! $claim->visitorUnlock->isActive() || ! $claim->last_revealed_at) {
            throw new InvalidArgumentException('Contact feedback needs an active revealed unlock.');
        }

        $outcome = $this->normalizeReachabilityOutcome($outcome);
        $negative = $outcome !== CustomerReachabilityFeedback::OUTCOME_REACHED;
        $recentNegatives = $negative
            ? CustomerReachabilityFeedback::query()
                ->where('platform_id', $account->platform_id)
                ->where('client_id', $claim->client_id)
                ->whereIn('outcome', [
                    CustomerReachabilityFeedback::OUTCOME_NO_ANSWER,
                    CustomerReachabilityFeedback::OUTCOME_WRONG_NUMBER,
                    CustomerReachabilityFeedback::OUTCOME_WHATSAPP_FAILED,
                ])
                ->where('submitted_at', '>=', Carbon::now()->subDays(30))
                ->count()
            : 0;

        $status = $negative && $recentNegatives >= 1
            ? CustomerReachabilityFeedback::STATUS_PENDING_REVIEW
            : CustomerReachabilityFeedback::STATUS_RECORDED;

        $feedback = CustomerReachabilityFeedback::query()->create([
            'customer_account_id' => (int) $account->id,
            'platform_id' => (int) $account->platform_id,
            'customer_unlock_claim_id' => (int) $claim->id,
            'visitor_contact_unlock_id' => (int) $claim->visitor_contact_unlock_id,
            'wp_post_id' => (int) $claim->wp_post_id,
            'client_id' => $claim->client_id,
            'outcome' => $outcome,
            'status' => $status,
            'review_reason' => $status === CustomerReachabilityFeedback::STATUS_PENDING_REVIEW
                ? CustomerReachabilityFeedback::REVIEW_REPEATED_NEGATIVE
                : null,
            'note' => $this->trimOrNull($note, 500),
            'submitted_at' => Carbon::now(),
        ]);

        $this->recordEvent($account, CustomerActivityEvent::EVENT_REACHABILITY_SUBMITTED, (int) $claim->wp_post_id, [
            'outcome' => $outcome,
            'feedback_status' => $status,
        ]);

        return $feedback;
    }

    private function serializeUnlockClaim(CustomerUnlockClaim $claim): array
    {
        $client = $claim->client;
        $visitorUnlock = $claim->visitorUnlock;
        $status = $visitorUnlock ? $this->claimStatus($visitorUnlock) : (string) $claim->status;

        return [
            'id' => (int) $claim->id,
            'visitor_contact_unlock_id' => (int) $claim->visitor_contact_unlock_id,
            'wp_post_id' => (int) $claim->wp_post_id,
            'client_id' => (int) ($claim->client_id ?? 0),
            'scope' => (string) $claim->scope,
            'status' => $status,
            'active' => $status === CustomerUnlockClaim::STATUS_ACTIVE,
            'claimed_at' => $claim->claimed_at?->toIso8601String(),
            'expires_at' => $claim->expires_at?->toIso8601String(),
            'last_revealed_at' => $claim->last_revealed_at?->toIso8601String(),
            'source' => (string) $claim->source,
            'profile' => [
                'name' => (string) ($client?->name ?? ''),
                'url' => (string) ($client?->wp_profile_permalink ?? ''),
                'image' => (string) ($client?->display_image_url ?: $client?->main_image_url ?: ''),
                'city' => (string) ($client?->city ?? ''),
            ],
        ];
    }

    private function findCompareSet(CustomerAccount $account): ?CustomerCompareSet
    {
        $key = (int) $account->id;

        if (!array_key_exists($key, $this->compareSetMemo)) {
            $this->compareSetMemo[$key] = CustomerCompareSet::query()
                ->where('customer_account_id', $account->id)
                ->first() ?? false;
        }

        return $this->compareSetMemo[$key] ?: null;
    }

    private function compareSet(CustomerAccount $account): CustomerCompareSet
    {
        $set = $this->findCompareSet($account);
        if ($set) {
            return $set;
        }

        $set = CustomerCompareSet::query()->create([
            'customer_account_id' => $account->id,
            'platform_id' => $account->platform_id,
            'last_activity_at' => Carbon::now(),
        ]);

        $this->compareSetMemo[(int) $account->id] = $set;

        return $set;
    }

    private function touchCompareSet(CustomerCompareSet $set): void
    {
        $set->last_activity_at = Carbon::now();
        $set->save();
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
            'object_type' => $objectRef !== null ? ($context['follow_type'] ?? CustomerSavedObject::TYPE_PROFILE) : null,
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
        CustomerRecentView::query()->where('customer_account_id', $account->id)->delete();
        CustomerCompareItem::query()->where('customer_account_id', $account->id)->delete();
        CustomerCompareSet::query()->where('customer_account_id', $account->id)->delete();
        CustomerFollow::query()->where('customer_account_id', $account->id)->delete();
        CustomerSavedSearch::query()->where('customer_account_id', $account->id)->delete();
        CustomerActivityEvent::query()->where('customer_account_id', $account->id)->delete();
        CustomerUnlockClaim::query()->where('customer_account_id', $account->id)->update(['customer_account_id' => null]);
        CustomerReachabilityFeedback::query()->where('customer_account_id', $account->id)->update(['customer_account_id' => null]);

        unset(
            $this->compareSetMemo[(int) $account->id],
            $this->compareItemsMemo[(int) $account->id],
            $this->recentCountMemo[(int) $account->id],
            $this->previousLastSeenMemo[(int) $account->id]
        );
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

    private function normalizeFollowType(string $type): string
    {
        $type = strtolower(trim($type));
        if (! in_array($type, [CustomerFollow::TYPE_PROFILE, CustomerFollow::TYPE_LOCATION], true)) {
            throw new InvalidArgumentException('A supported follow type is required.');
        }

        return $type;
    }

    private function normalizePositiveRef(int $objectRef, string $message): int
    {
        $objectRef = (int) $objectRef;
        if ($objectRef < 1) {
            throw new InvalidArgumentException($message);
        }

        return $objectRef;
    }

    private function normalizeRoutePart(string $value, int $max, string $message): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_:\\/-]+/', '', $value) ?? '';
        $value = trim($value, '/');
        if ($value === '') {
            throw new InvalidArgumentException($message);
        }

        return mb_substr($value, 0, $max);
    }

    private function claimStatus(VisitorContactUnlock $unlock): string
    {
        if ((string) $unlock->status === VisitorContactUnlock::STATUS_REVOKED) {
            return CustomerUnlockClaim::STATUS_REVOKED;
        }

        return $unlock->isActive() ? CustomerUnlockClaim::STATUS_ACTIVE : CustomerUnlockClaim::STATUS_EXPIRED;
    }

    private function canRevealTarget(VisitorContactUnlock $unlock, Client $target): bool
    {
        if ((int) $unlock->platform_id !== (int) $target->platform_id) {
            return false;
        }

        if ((string) $unlock->scope === VisitorContactUnlock::SCOPE_SINGLE_PROFILE) {
            return (int) $unlock->wp_post_id === (int) $target->wp_post_id;
        }

        if ((string) $unlock->scope === VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES) {
            return $target->isPubliclyRestricted();
        }

        return false;
    }

    private function normalizeReachabilityOutcome(string $outcome): string
    {
        $outcome = strtolower(trim($outcome));
        if (! in_array($outcome, [
            CustomerReachabilityFeedback::OUTCOME_REACHED,
            CustomerReachabilityFeedback::OUTCOME_NO_ANSWER,
            CustomerReachabilityFeedback::OUTCOME_WRONG_NUMBER,
            CustomerReachabilityFeedback::OUTCOME_WHATSAPP_FAILED,
        ], true)) {
            throw new InvalidArgumentException('Choose what happened after revealing the contact.');
        }

        return $outcome;
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    /**
     * @param array<string,mixed> $refinements
     * @return array<string,mixed>
     */
    private function normalizeRefinements(array $refinements): array
    {
        $normalized = [];

        foreach (['q', 'city', 'fresh', 'verified', 'premium', 'vip', 'filters'] as $key) {
            if (!array_key_exists($key, $refinements)) {
                continue;
            }

            $value = $refinements[$key];
            if ($key === 'filters') {
                $filters = is_array($value) ? $value : [$value];
                $filters = array_values(array_unique(array_filter(array_map(
                    static fn ($filter) => preg_replace('/[^a-z0-9_]+/', '', strtolower(trim((string) $filter))) ?: '',
                    $filters
                ))));
                if (!empty($filters)) {
                    sort($filters);
                    $normalized[$key] = array_slice($filters, 0, 20);
                }
                continue;
            }

            if (in_array($key, ['city', 'verified', 'premium', 'vip'], true)) {
                $int = (int) $value;
                if ($int > 0) {
                    $normalized[$key] = $int;
                }
                continue;
            }

            $text = trim((string) $value);
            if ($text !== '') {
                $normalized[$key] = mb_substr($text, 0, $key === 'q' ? 120 : 40);
            }
        }

        ksort($normalized);

        return $normalized;
    }
}
