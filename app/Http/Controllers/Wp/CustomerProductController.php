<?php

namespace App\Http\Controllers\Wp;

use App\Http\Controllers\Controller;
use App\Models\CustomerActivityEvent;
use App\Models\CustomerRecentView;
use App\Models\CustomerSavedObject;
use App\Models\Platform;
use App\Services\CustomerProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Customer (site member) product state, called server-to-server by the
 * WordPress `exotic-crm-sync` plugin over HMAC.
 *
 * The HMAC middleware proves which *site* is calling; it proves nothing about
 * which *person*. WordPress derives the member identity from its own session
 * and signs it into the body, so every action here re-validates that identity
 * through CustomerProductService before touching state.
 */
class CustomerProductController extends Controller
{
    public function __construct(
        private readonly CustomerProductService $customerProduct,
    ) {}

    /** Link or refresh the account and return its workspace summary. */
    public function sync(Request $request): JsonResponse
    {
        return $this->withAccount($request, function ($account, Platform $platform) {
            $this->customerProduct->recordEvent($account, CustomerActivityEvent::EVENT_WORKSPACE_VIEWED);

            return response()->json($this->summary($account));
        });
    }

    /** Saved profile ids, newest first. */
    public function savedIndex(Request $request): JsonResponse
    {
        return $this->withAccount($request, function ($account) {
            return response()->json($this->summary($account));
        });
    }

    public function savedStore(Request $request): JsonResponse
    {
        return $this->withAccount($request, function ($account) use ($request) {
            $created = $this->customerProduct->save(
                $account,
                (int) $request->input('object_ref'),
                CustomerSavedObject::SOURCE_WORKSPACE
            );

            return response()->json($this->summary($account) + [
                'created' => $created,
                'saved' => true,
            ], $created ? 201 : 200);
        });
    }

    public function savedDestroy(Request $request): JsonResponse
    {
        return $this->withAccount($request, function ($account) use ($request) {
            $removed = $this->customerProduct->unsave($account, (int) $request->input('object_ref'));

            return response()->json($this->summary($account) + [
                'removed' => $removed,
                'saved' => false,
            ]);
        });
    }

    /**
     * Fold a batch of profile ids into the account: the legacy WordPress
     * `user_meta.favorites` backfill, and the signed-out browser-local list
     * merged on first authenticated load.
     */
    public function savedMerge(Request $request): JsonResponse
    {
        return $this->withAccount($request, function ($account) use ($request) {
            $source = (string) $request->input('source', CustomerSavedObject::SOURCE_LOCAL_MERGE);
            $allowed = [
                CustomerSavedObject::SOURCE_LEGACY_BACKFILL,
                CustomerSavedObject::SOURCE_LOCAL_MERGE,
            ];
            if (! in_array($source, $allowed, true)) {
                $source = CustomerSavedObject::SOURCE_LOCAL_MERGE;
            }

            $refs = $request->input('object_refs', []);
            if (! is_array($refs)) {
                $refs = [];
            }

            $merged = $this->customerProduct->merge($account, $refs, $source);

            return response()->json($this->summary($account) + [
                'merged' => $merged,
            ]);
        });
    }

    // ------------------------------------------------------------ recent views

    /** Recent views, newest first, plus the workspace summary. */
    public function recentIndex(Request $request): JsonResponse
    {
        return $this->withAccount($request, function ($account) use ($request) {
            $limit = (int) $request->input('limit', CustomerProductService::RECENT_VIEWS_PAGE);

            return response()->json($this->summary($account) + [
                'recent_views' => $this->customerProduct->recentViews($account, $limit),
            ]);
        });
    }

    /**
     * Record that the member opened a profile.
     *
     * The hottest endpoint in the customer product: it fires once per profile
     * page view per signed-in member. It deliberately does NOT return the
     * workspace summary — the caller is fire-and-forget and discards the body,
     * and building the summary would add a saved-ids read, a compare-set read,
     * and a count to every profile view for nothing.
     */
    public function recentStore(Request $request): JsonResponse
    {
        return $this->withAccount($request, function ($account) use ($request) {
            $created = $this->customerProduct->recordView($account, (int) $request->input('object_ref'));

            return response()->json([
                'success' => true,
                'created' => $created,
            ], $created ? 201 : 200);
        });
    }

    /** Clear-history. */
    public function recentClear(Request $request): JsonResponse
    {
        return $this->withAccount($request, function ($account) {
            $cleared = $this->customerProduct->clearRecentViews($account);

            return response()->json($this->summary($account) + [
                'cleared' => $cleared,
                'recent_views' => [],
            ]);
        });
    }

    // ----------------------------------------------------------------- compare

    public function compareIndex(Request $request): JsonResponse
    {
        return $this->withAccount($request, function ($account) {
            return response()->json($this->summary($account));
        });
    }

    /** Add to the tray. A fifth profile is a 422 the tray can show, not a crash. */
    public function compareStore(Request $request): JsonResponse
    {
        return $this->withAccount($request, function ($account) use ($request) {
            $created = $this->customerProduct->addToCompare($account, (int) $request->input('object_ref'));

            return response()->json($this->summary($account) + [
                'created' => $created,
                'in_compare' => true,
            ], $created ? 201 : 200);
        });
    }

    public function compareDestroy(Request $request): JsonResponse
    {
        return $this->withAccount($request, function ($account) use ($request) {
            $removed = $this->customerProduct->removeFromCompare($account, (int) $request->input('object_ref'));

            return response()->json($this->summary($account) + [
                'removed' => $removed,
                'in_compare' => false,
            ]);
        });
    }

    public function compareClear(Request $request): JsonResponse
    {
        return $this->withAccount($request, function ($account) {
            $cleared = $this->customerProduct->clearCompare($account);

            return response()->json($this->summary($account) + [
                'cleared' => $cleared,
            ]);
        });
    }

    /** Delete every trace of a customer. Called from the WordPress user-delete hook. */
    public function forget(Request $request): JsonResponse
    {
        $platform = $request->attributes->get('platform');

        try {
            $deleted = $this->customerProduct->forget($platform, (int) $request->input('wp_user_id'));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'deleted' => $deleted,
        ]);
    }

    /**
     * Resolve the signed member identity, then run the action. Identity failures
     * are 422 so the WordPress side can distinguish them from transport errors.
     */
    private function withAccount(Request $request, callable $action): JsonResponse
    {
        $platform = $request->attributes->get('platform');

        try {
            $account = $this->customerProduct->resolveAccount($platform, [
                'wp_user_id' => $request->input('wp_user_id'),
                'wp_role' => $request->input('wp_role'),
                'display_name' => $request->input('display_name'),
                'email' => $request->input('email'),
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        try {
            return $action($account, $platform);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    /**
     * The workspace summary every action returns, so one round trip keeps the
     * save control, the compare tray and the counters in agreement.
     *
     * Compare ids are always included because the tray holds at most four. The
     * recent-view *list* is not: it is a wider read that only the recent-view
     * endpoints pay for, so a save never drags sixty rows along with it.
     */
    private function summary($account): array
    {
        $savedIds = $this->customerProduct->savedProfileIds($account);
        $compareIds = $this->customerProduct->compareProfileIds($account);

        return [
            'success' => true,
            'customer_account_id' => (int) $account->id,
            'saved_profile_ids' => $savedIds,
            'saved_count' => count($savedIds),
            'saved_limit' => CustomerProductService::MAX_SAVED_OBJECTS,
            'compare_profile_ids' => $compareIds,
            'compare_count' => count($compareIds),
            'compare_limit' => CustomerProductService::MAX_COMPARE_ITEMS,
            'recent_count' => $this->customerProduct->recentViewCount($account),
            'recent_limit' => CustomerRecentView::MAX_PER_ACCOUNT,
        ];
    }
}
