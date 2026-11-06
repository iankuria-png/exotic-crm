<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Collections\CollectionReceipt;
use App\Models\User;
use App\Services\MarketAuthorizationService;

class CollectionAccessService
{
    public function accountIds(User $user): array
    {
        app(MarketAuthorizationService::class)->ensureRole($user, ['admin', 'sub_admin', 'sales']);
        $allowed = app(MarketAuthorizationService::class)->resolveAccessiblePlatformIds($user);
        $query = CollectionAccount::whereExists(fn ($q) => $q->selectRaw('1')->from('collection_account_platforms')->whereColumn('account_id', 'collection_accounts.id'));
        if (is_array($allowed)) {
            // Shared receiving text is visible only when every bound market is in scope.
            $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('collection_account_platforms')->whereColumn('account_id', 'collection_accounts.id')->whereNotIn('platform_id', $allowed));
        }

        $ids = $query->pluck('id')->all();
        if (is_array($allowed) && $ids) {
            // A paid account may expose a separate linked free-exposure profile.
            // Keep its receipt/audit text private unless staff can see both markets,
            // including prior purchase authorities retained in correction history.
            $blocked = CollectionPurchaseIntent::whereIn('account_id', $ids)->get(['account_id', 'platform_id', 'purchase_snapshot'])
                ->filter(fn ($intent) => ! in_array((int) $intent->platform_id, $allowed, true)
                    || (data_get($intent->purchase_snapshot, 'subsidiary.platform_id')
                        && ! in_array((int) data_get($intent->purchase_snapshot, 'subsidiary.platform_id'), $allowed, true)))
                ->pluck('account_id')->unique()->all();
            $ids = array_values(array_diff($ids, $blocked));
        }

        return $ids;
    }

    public function receipt(User $actor, int $id): CollectionReceipt
    {
        return CollectionReceipt::whereIn('account_id', $this->accountIds($actor))->findOrFail($id);
    }

    public function account(User $actor, string $id): CollectionAccount
    {
        return CollectionAccount::whereIn('id', $this->accountIds($actor))->findOrFail($id);
    }
}
